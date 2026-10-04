<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Immich smart (CLIP) search, with each hit mapped back to the real Nextcloud file.
 *
 * Immich reads the Nextcloud data dir read-only as an external library mounted at
 * /mnt/nextcloud/<uid>/ (~/docker-projects/immich/docker-compose.yml), so
 * "/mnt/nextcloud/florian/Fotos/a.jpg" is "/Fotos/a.jpg" in florian's files.
 */
class AssetSearch {
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MOUNT_PREFIX = '/mnt/nextcloud/';
    private const TYPES = ['IMAGE', 'VIDEO'];

    public function __construct(
        private ImmichClient $immich,
        private IRootFolder $rootFolder,
    ) {
    }

    /**
     * @param string $q free text; empty means "everything", newest first (useful with $person)
     * @return array{items: list<array>, nextPage: ?int}
     */
    public function search(string $uid, string $q, int $page, int $size, string $person = '', string $type = ''): array {
        $body = ['page' => max(1, $page), 'size' => max(1, min(200, $size))];
        if (in_array($type, self::TYPES, true)) {
            $body['type'] = $type;
        }
        if ($person !== '' && preg_match(self::UUID, $person)) {
            $body['personIds'] = [$person];
        }
        if ($q !== '') {
            $endpoint = '/search/smart';
            $body['query'] = $q;
        } else {
            // Smart search needs text to rank by; metadata search lists by date instead.
            $endpoint = '/search/metadata';
            $body['order'] = 'desc';
        }

        $assets = $this->immich->json($uid, 'POST', $endpoint, $body)['assets'] ?? [];
        $folder = $this->rootFolder->getUserFolder($uid);
        $items = array_map(fn (array $a) => $this->toItem($uid, $folder, $a), $assets['items'] ?? []);
        $next = $assets['nextPage'] ?? null;
        return ['items' => $items, 'nextPage' => $next === null ? null : (int)$next];
    }

    /** @return list<array{id: string, name: string}> named, visible people, sorted by name */
    public function people(string $uid): array {
        $raw = $this->immich->json($uid, 'GET', '/people?withHidden=false&size=1000')['people'] ?? [];
        $named = array_values(array_filter($raw, static fn (array $p) => ($p['name'] ?? '') !== ''));
        $people = array_map(static fn (array $p) => ['id' => $p['id'], 'name' => $p['name']], $named);
        usort($people, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $people;
    }

    private function toItem(string $uid, Folder $folder, array $a): array {
        $path = $this->toNextcloudPath($uid, $a['originalPath'] ?? '');
        $node = null;
        if ($path !== null) {
            try {
                $node = $folder->get($path);
            } catch (NotFoundException) {
                // Moved or deleted since Immich last scanned the library.
            }
        }
        return [
            'id' => $a['id'],
            'type' => $a['type'] ?? 'IMAGE',
            'date' => $a['localDateTime'] ?? $a['fileCreatedAt'] ?? null,
            'name' => $a['originalFileName'] ?? ($path !== null ? basename($path) : ''),
            'path' => $node !== null ? $path : null,
            'fileId' => $node?->getId(),
            'mime' => $node?->getMimetype(),
        ];
    }

    private function toNextcloudPath(string $uid, string $immichPath): ?string {
        $prefix = self::MOUNT_PREFIX . $uid . '/';
        if (!str_starts_with($immichPath, $prefix)) {
            return null;
        }
        return '/' . substr($immichPath, strlen($prefix));
    }
}
