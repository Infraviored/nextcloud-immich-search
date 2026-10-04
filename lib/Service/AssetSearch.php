<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;

/**
 * Immich smart (CLIP) search, filtered by Nextcloud's own permissions.
 *
 * Immich indexes every user's Nextcloud files read-only as external libraries
 * under one service account. Config::getPathPattern() says where: with
 * "/mnt/nextcloud/{user}/", the hit "/mnt/nextcloud/alice/Photos/a.jpg" is
 * alice's "/Photos/a.jpg". It is shown to the searching user only if that file
 * is reachable in their own Nextcloud (own file or shared with them), under the
 * path they know it by ("/Shared/Photos/a.jpg" for a share recipient).
 *
 * Assets that passed the check are remembered per user, so the thumbnail
 * endpoint can authorize without asking Immich again.
 */
class AssetSearch {
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const TYPES = ['IMAGE', 'VIDEO'];
    private const GRANT_TTL = 86400;
    /** Immich pages fetched at most per call when the permission filter drops most hits. */
    private const MAX_PAGES_PER_CALL = 5;
    /** Owner lookups kept in memory, enough for one person's pictures in PeopleIndex. */
    private const OWNER_MEMO = 5000;

    private ICache $grants;
    /** @var array<string, ?Folder> */
    private array $folders = [];
    /** @var array<string, array{0: ?string, 1: ?Node}> */
    private array $owners = [];

    public function __construct(
        private ImmichClient $immich,
        private Config $config,
        private IRootFolder $rootFolder,
        private IUserManager $users,
        ICacheFactory $cacheFactory,
    ) {
        $this->grants = $cacheFactory->createDistributed('immich_search_grants');
    }

    /**
     * @param string $q free text; empty means newest first (useful with $person)
     * @return array{items: list<array>, nextPage: ?int} only items the user may see. Keeps
     *         fetching Immich pages until half a page is filled, so a user who may see little
     *         does not get a string of empty pages.
     */
    public function search(string $uid, string $q, int $page, int $size, string $person = '', string $type = ''): array {
        $size = max(1, min(200, $size));
        $items = [];
        $next = max(1, $page);
        for ($i = 0; $i < self::MAX_PAGES_PER_CALL && $next !== null && count($items) < intdiv($size + 1, 2); $i++) {
            $assets = $this->immichPage($q, $next, $size, $person, $type);
            foreach ($assets['items'] ?? [] as $a) {
                $item = $this->visibleItem($uid, $a);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            $next = isset($assets['nextPage']) ? (int)$assets['nextPage'] : null;
        }
        return ['items' => $items, 'nextPage' => $next];
    }

    /** May $uid see this asset? Asks Immich for its path if the user has not been shown it yet. */
    public function maySee(string $uid, string $assetId): bool {
        if ($this->grants->get($uid . '/' . $assetId) !== null) {
            return true;
        }
        try {
            $asset = $this->immich->json('GET', '/assets/' . $assetId);
        } catch (ImmichException) {
            return false;
        }
        return $this->visibleItem($uid, $asset) !== null;
    }

    /** @return list<array{id: string, name: string}> named, visible people, sorted by name */
    public function people(): array {
        $raw = $this->immich->json('GET', '/people?withHidden=false&size=1000')['people'] ?? [];
        $people = [];
        foreach ($raw as $p) {
            if (($p['name'] ?? '') !== '') {
                $people[] = ['id' => $p['id'], 'name' => $p['name']];
            }
        }
        usort($people, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $people;
    }

    private function immichPage(string $q, int $page, int $size, string $person, string $type): array {
        $body = ['page' => $page, 'size' => $size];
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
        return $this->immich->json('POST', $endpoint, $body)['assets'] ?? [];
    }

    /**
     * The hit as $uid sees it, or null if the file is not reachable for $uid.
     * $grant remembers the asset for the thumbnail check; bulk callers (PeopleIndex) skip that.
     */
    public function visibleItem(string $uid, array $a, bool $grant = true): ?array {
        $node = $this->nodeFor($uid, $a['originalPath'] ?? '');
        if ($node === null) {
            return null;
        }
        if ($grant) {
            $this->grants->set($uid . '/' . $a['id'], 1, self::GRANT_TTL);
        }
        return [
            'id' => $a['id'],
            'type' => $a['type'] ?? 'IMAGE',
            'date' => $a['localDateTime'] ?? $a['fileCreatedAt'] ?? null,
            'name' => $node->getName(),
            'path' => $this->userFolder($uid)->getRelativePath($node->getPath()),
            'fileId' => $node->getId(),
            'mime' => $node->getMimetype(),
        ];
    }

    private function nodeFor(string $uid, string $immichPath): ?Node {
        [$owner, $file] = $this->ownerNode($immichPath);
        $mine = $this->userFolder($uid);
        if ($file === null || $mine === null) {
            return null;
        }
        if ($owner === $uid) {
            return $file;
        }
        // A file has the same id everywhere; it resolves in $uid's tree only if shared with them.
        return $mine->getFirstNodeById($file->getId());
    }

    /**
     * Owner and owner's node for an Immich path, memoized: PeopleIndex asks once per user.
     * @return array{0: ?string, 1: ?Node}
     */
    private function ownerNode(string $immichPath): array {
        if (array_key_exists($immichPath, $this->owners)) {
            return $this->owners[$immichPath];
        }
        if (count($this->owners) > self::OWNER_MEMO) {
            $this->owners = [];
        }
        [$owner, $rel] = $this->splitImmichPath($immichPath) ?? [null, null];
        $folder = $owner !== null ? $this->userFolder($owner) : null;
        $node = null;
        if ($folder !== null) {
            try {
                $node = $folder->get($rel);
            } catch (NotFoundException) {
                // moved or deleted since Immich last scanned
            }
        }
        return $this->owners[$immichPath] = [$owner, $node];
    }

    /** "/mnt/nextcloud/alice/Photos/a.jpg" -> ["alice", "Photos/a.jpg"], null if outside the pattern. */
    private function splitImmichPath(string $path): ?array {
        [$before, $after] = explode(Config::USER_PLACEHOLDER, $this->config->getPathPattern(), 2);
        if (!str_starts_with($path, $before)) {
            return null;
        }
        $rest = substr($path, strlen($before));
        $end = $after === '' ? false : strpos($rest, $after);
        if ($end === false || $end === 0) {
            return null;
        }
        $owner = substr($rest, 0, $end);
        $rel = substr($rest, $end + strlen($after));
        return str_contains($owner, '/') || $rel === '' ? null : [$owner, $rel];
    }

    private function userFolder(string $uid): ?Folder {
        if (!array_key_exists($uid, $this->folders)) {
            $this->folders[$uid] = $this->users->userExists($uid) ? $this->rootFolder->getUserFolder($uid) : null;
        }
        return $this->folders[$uid];
    }
}
