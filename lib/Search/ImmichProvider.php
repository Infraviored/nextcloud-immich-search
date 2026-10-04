<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Search;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\AssetSearch;
use OCA\ImmichSearch\Service\Config;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Global search bar: "Frau am Strand" finds pictures by what is on them.
 * Hits link to the real Nextcloud file and carry the same fileId/path attributes
 * as the Files provider, so the Android app opens them in-app.
 */
class ImmichProvider implements IProvider {
    /** Shorter terms are almost always the start of a file name search. */
    private const MIN_TERM = 3;

    public function __construct(
        private AssetSearch $search,
        private Config $config,
        private IURLGenerator $url,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Fotos nach Inhalt';
    }

    public function getOrder(string $route, array $routeParameters): int {
        // Directly below Files (order 5) where people look for pictures, lower elsewhere.
        foreach (['files.', 'photos.', 'memories.', Application::APP_ID . '.'] as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return 6;
            }
        }
        return 25;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $uid = $user->getUID();
        $term = trim($query->getTerm());
        if (mb_strlen($term) < self::MIN_TERM || !$this->config->isConfigured($uid)) {
            return SearchResult::complete($this->getName(), []);
        }

        // Immich pages are fixed-size, so the cursor is an offset that is a multiple of the limit.
        $limit = max(1, $query->getLimit());
        $offset = max(0, (int)$query->getCursor());
        try {
            $res = $this->search->search($uid, $term, intdiv($offset, $limit) + 1, $limit);
        } catch (\Throwable $e) {
            // Immich down, or its ML container stopped by gpu-guard: no results, no error page.
            $this->logger->info('Fotosuche (globale Suche): ' . $e->getMessage(), ['app' => Application::APP_ID]);
            return SearchResult::complete($this->getName(), []);
        }

        $entries = array_map(fn (array $a) => $this->entry($a, $term), $res['items']);
        return $res['nextPage'] === null
            ? SearchResult::complete($this->getName(), $entries)
            : SearchResult::paginated($this->getName(), $entries, $offset + $limit);
    }

    private function entry(array $a, string $term): SearchResultEntry {
        $thumb = $this->url->linkToRouteAbsolute('immich_search.api.thumbnail', ['id' => $a['id']]);
        $link = $a['fileId'] !== null
            ? $this->url->linkToRouteAbsolute('files.View.showFile', ['fileid' => $a['fileId']])
            : $this->url->linkToRouteAbsolute('immich_search.page.index') . '?q=' . rawurlencode($term);

        $sub = [];
        if ($a['type'] === 'VIDEO') {
            $sub[] = 'Video';
        }
        if ($a['date'] !== null) {
            $sub[] = date('d.m.Y', strtotime($a['date']));
        }
        if ($a['path'] !== null) {
            $sub[] = ltrim(dirname($a['path']), '/') ?: 'Dateien';
        }

        $entry = new SearchResultEntry($thumb, $a['name'], implode(' · ', $sub), $link,
            $a['type'] === 'VIDEO' ? 'icon-video' : 'icon-picture');
        if ($a['fileId'] !== null) {
            $entry->addAttribute('fileId', (string)$a['fileId']);
            $entry->addAttribute('path', $a['path']);
        }
        return $entry;
    }
}
