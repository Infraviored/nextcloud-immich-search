<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\SensitiveContent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ glimpse:sensitive — check the setup, or recompute a user's sensitive content now. */
class SensitiveCommand extends Command {
	public function __construct(
		private SensitiveContent $sensitive,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:sensitive')
			->setDescription('Sensitive content: check the setup, or recompute it for users who turned it on')
			->addArgument('user', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Recompute for these users (default: all who turned it on)')
			->addOption('check', null, InputOption::VALUE_NONE, 'Only check database access and the machine-learning service')
			->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role when sharing: editor or user', 'editor');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$users = (array)$input->getArgument('user');
		if (($users[0] ?? '') === 'share') {
			$owner = (string)($users[1] ?? '');
			$member = (string)($users[2] ?? '');
			$role = (string)$input->getOption('role') ?: 'editor';
			if ($owner === '' || $member === '') {
				$output->writeln('<error>Usage: occ glimpse:sensitive share <owner> <member> [--role=editor]</error>');
				return self::FAILURE;
			}
			try {
				$this->sensitive->share($owner, $member, $role);
				$output->writeln(sprintf('Shared %s\'s filter with %s (role: %s)', $owner, $member, $role));
				return self::SUCCESS;
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return self::FAILURE;
			}
		}
		$check = $this->sensitive->check();
		$output->writeln('Database:         ' . ($check['database'] ?? 'ok'));
		$output->writeln('Machine learning: ' . ($check['ml'] ?? 'ok') . ($check['model'] !== null ? ' (' . $check['model'] . ')' : ''));
		$output->writeln('Allowed by admin: ' . ($this->sensitive->isAvailable() ? 'yes' : 'no (sensitive_detection)'));
		if ($input->getOption('check')) {
			return $check['database'] === null && $check['ml'] === null ? self::SUCCESS : self::FAILURE;
		}
		$users = $users ?: $this->sensitive->users();
		$failed = false;
		foreach ($users as $uid) {
			$start = microtime(true);
			try {
				$r = $this->sensitive->rebuild($uid);
				$learned = $this->sensitive->status($uid)['learned'];
				$output->writeln(sprintf('%s: %d terms; %s (%d marked sensitive, %d not), %d more from it; %d flagged now (%.1fs)',
					$uid, $r['terms'], $r['model'] ? 'model trained' : 'no model yet', $learned['positives'], $learned['negatives'], $r['byModel'], $r['flagged'], microtime(true) - $start));
				foreach ($this->sensitive->status($uid)['terms'] as $t) {
					$output->writeln(sprintf('  "%s": %d%s', $t['q'], $t['count'], $t['stale'] ? ' (made with another search model, set again)' : ''));
				}
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
				$failed = true;
			}
		}
		return $failed ? self::FAILURE : self::SUCCESS;
	}
}
