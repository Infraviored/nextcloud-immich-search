<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Command;

use OCA\Glimpse\Service\SensitiveContent;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ glimpse:sensitive:share <owner> <member> [--role=editor] */
class SensitiveShareCommand extends Command {
	public function __construct(
		private SensitiveContent $sensitive,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('glimpse:sensitive:share')
			->setAliases(['sensitive:share'])
			->setDescription('Share a sensitive filter with another user')
			->addArgument('owner', InputArgument::REQUIRED, 'User who owns the filter')
			->addArgument('member', InputArgument::REQUIRED, 'User to share the filter with')
			->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role for the member: editor or user', 'editor');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$owner = (string)$input->getArgument('owner');
		$member = (string)$input->getArgument('member');
		$role = (string)$input->getOption('role') ?: 'editor';
		if (!in_array($role, ['editor', 'user'], true)) {
			$output->writeln('<error>Role must be editor or user</error>');
			return self::FAILURE;
		}
		if ($this->userManager->get($owner) === null) {
			$output->writeln('<error>Owner user not found: ' . $owner . '</error>');
			return self::FAILURE;
		}
		if ($this->userManager->get($member) === null) {
			$output->writeln('<error>Member user not found: ' . $member . '</error>');
			return self::FAILURE;
		}
		if ($owner === $member) {
			$output->writeln('<error>Owner and member must be different users</error>');
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
}
