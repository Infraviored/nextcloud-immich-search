<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Search;

use OCA\Glimpse\AppInfo\Application;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\PeopleNames;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\UserPrefs;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/**
 * Global search bar: "woman on the beach" finds pictures by what is on them.
 * Only files the user can open in Nextcloud are returned (see AssetSearch).
 * Hits link to the real Nextcloud file and carry the same fileId/path attributes
 * as the Files provider, so the Android app opens them in-app.
 */
class ImmichProvider implements IProvider {
	private IL10N $l;

	public function __construct(
		private AssetSearch $search,
		private Config $config,
		private UserPrefs $prefs,
		private PeopleNames $names,
		private PrivateArea $privateArea,
		private IURLGenerator $url,
		IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
		// The app's translations, also when built outside the app's DI container.
		$this->l = $l10nFactory->get(Application::APP_ID);
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Photos by content');
	}

	public function getOrder(string $route, array $routeParameters): int {
		if ($this->config->get('search_position') === 'low') {
			return 40;
		}
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
		if (mb_strlen($term) < max(1, (int)$this->config->get('search_min_term'))
			|| !$this->config->isConfigured()
			|| !$this->config->get('search_photos')
			|| $this->prefs->get($uid, 'search_disabled')) {
			return SearchResult::complete($this->getName(), []);
		}

		// The cursor is the next Immich page. One call can consume several pages
		// (AssetSearch skips hits the user may not see), so it is not an offset.
		$page = max(1, (int)$query->getCursor());
		try {
			// "Tara beach": Tara's pictures, ranked by "beach"
			[$person, $text] = $this->names->split($uid, $term);
			$res = $this->search->search($uid, $text, $page, max(1, $query->getLimit()), $person ?? '');
		} catch (\Throwable $e) {
			// Immich or its machine-learning container down: no results, no error page.
			$this->logger->info('Glimpse (unified search): ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return SearchResult::complete($this->getName(), []);
		}

		// Private pictures never show up here, not even when unlocked in the app.
		$entries = array_map(fn (array $a) => $this->entry($a), $this->privateArea->filter($uid, $res['items'], false));
		if ($query->getCursor() === null && $entries !== [] && $this->config->get('search_open_all')) {
			// The search bar cannot select or move; this hands the search over to the app.
			array_unshift($entries, new SearchResultEntry(
				$this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')),
				$this->l->t('Open all results in Glimpse'),
				$this->l->t('Select several, move into folders'),
				$this->url->linkToRouteAbsolute('glimpse.page.index') . '?q=' . rawurlencode($term),
				'icon-search',
			));
		}
		return $res['nextPage'] === null
			? SearchResult::complete($this->getName(), $entries)
			: SearchResult::paginated($this->getName(), $entries, $res['nextPage']);
	}

	private function entry(array $a): SearchResultEntry {
		$thumb = $this->url->linkToRouteAbsolute('glimpse.api.thumbnail', ['id' => $a['id']]);
		$link = $this->url->linkToRouteAbsolute('files.View.showFile', ['fileid' => $a['fileId']]);

		$sub = [];
		if ($a['type'] === 'VIDEO') {
			$sub[] = $this->l->t('Video');
		}
		if ($a['date'] !== null) {
			$sub[] = $this->l->l('date', strtotime($a['date']));
		}
		$sub[] = ltrim(dirname($a['path']), '/') ?: $this->l->t('Files');

		$entry = new SearchResultEntry($thumb, $a['name'], implode(' · ', $sub), $link,
			$a['type'] === 'VIDEO' ? 'icon-video' : 'icon-picture');
		// Same attributes the Files provider sets; the mobile apps use them to open the file in-app.
		$entry->addAttribute('fileId', (string)$a['fileId']);
		$entry->addAttribute('path', $a['path']);
		return $entry;
	}
}
