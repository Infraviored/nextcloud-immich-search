<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ImmichSearch\Search;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCA\ImmichSearch\Service\PeopleNames;
use OCA\ImmichSearch\Service\PrivateArea;
use OCA\ImmichSearch\Service\UserPrefs;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use Psr\Log\LoggerInterface;

/** Global search: a person's name leads to their page with all their pictures. */
class PeopleProvider implements IProvider {
	private IL10N $l;

	private const MIN_TERM = 2;

	public function __construct(
		private PeopleNames $names,
		private PrivateArea $privateArea,
		private Config $config,
		private UserPrefs $prefs,
		private IURLGenerator $url,
		IFactory $l10nFactory,
		private LoggerInterface $logger,
	) {
		// The app's translations, also when built outside the app's DI container.
		$this->l = $l10nFactory->get(Application::APP_ID);
	}

	public function getId(): string {
		return Application::APP_ID . '_people';
	}

	public function getName(): string {
		return $this->l->t('People in photos');
	}

	public function getOrder(string $route, array $routeParameters): int {
		return $this->config->get('search_position') === 'low' ? 41 : 7;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$uid = $user->getUID();
		$term = trim($query->getTerm());
		if (mb_strlen($term) < self::MIN_TERM || !$this->config->isConfigured()
			|| !$this->config->get('search_people') || $this->prefs->get($uid, 'search_disabled')) {
			return SearchResult::complete($this->getName(), []);
		}
		try {
			$private = $this->privateArea->get($uid)['people'];
			$hits = array_values(array_filter($this->names->find($uid, $term), static fn ($p) => !in_array($p['id'], $private, true)));
		} catch (\Throwable $e) {
			$this->logger->info('People search: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return SearchResult::complete($this->getName(), []);
		}
		$entries = array_map(fn (array $p) => new SearchResultEntry(
			$this->url->linkToRouteAbsolute('immich_search.people.face', ['id' => $p['id']]),
			$p['name'],
			$this->l->n('%n picture', '%n pictures', $p['count']),
			$this->url->linkToRouteAbsolute('immich_search.page.indexperson', ['id' => $p['id']]),
			'icon-user',
			true,
		), array_slice($hits, 0, max(1, $query->getLimit())));
		return SearchResult::complete($this->getName(), $entries);
	}
}
