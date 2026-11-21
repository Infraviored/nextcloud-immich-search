<?php

/**
 * SPDX-FileCopyrightText: 2026 Florian Schneider
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Glimpse\Tests\Unit\Service;

use OCA\Glimpse\Command\SensitiveCommand;
use OCA\Glimpse\Command\SensitiveShareCommand;
use OCA\Glimpse\Service\AssetSearch;
use OCA\Glimpse\Service\Config;
use OCA\Glimpse\Service\ImmichClient;
use OCA\Glimpse\Service\ImmichDb;
use OCA\Glimpse\Service\PrivateArea;
use OCA\Glimpse\Service\SensitiveContent;
use OCA\Glimpse\Service\UserPrefs;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

class SensitiveSharedFilterTest extends TestCase {
	private array $files = [];
	private array $prefs = [];
	private array $users = [];
	private bool $allowed = true;

	private function mockFolder(string $prefix = ''): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function ($name) use ($prefix) {
			$key = $prefix !== '' ? $prefix . '/' . $name : $name;
			if (!isset($this->files[$key])) {
				throw new NotFoundException();
			}
			$f = $this->createMock(ISimpleFile::class);
			$f->method('getContent')->willReturn($this->files[$key]);
			return $f;
		});
		$folder->method('newFile')->willReturnCallback(function ($name, $content) use ($prefix) {
			$key = $prefix !== '' ? $prefix . '/' . $name : $name;
			$this->files[$key] = $content;
			return $this->createMock(ISimpleFile::class);
		});
		$folder->method('getFolder')->willReturnCallback(fn ($name) => $this->mockFolder($prefix !== '' ? $prefix . '/' . $name : $name));
		$folder->method('newFolder')->willReturnCallback(fn ($name) => $this->mockFolder($prefix !== '' ? $prefix . '/' . $name : $name));
		$folder->method('getDirectoryListing')->willReturnCallback(function () use ($prefix) {
			$listing = [];
			$matchPrefix = $prefix !== '' ? $prefix . '/' : '';
			foreach (array_keys($this->files) as $k) {
				if ($matchPrefix === '' && !str_contains($k, '/')) {
					$f = $this->createMock(ISimpleFile::class);
					$f->method('getName')->willReturn($k);
					$listing[] = $f;
				} elseif ($matchPrefix !== '' && str_starts_with($k, $matchPrefix)) {
					$sub = substr($k, strlen($matchPrefix));
					if (!str_contains($sub, '/')) {
						$f = $this->createMock(ISimpleFile::class);
						$f->method('getName')->willReturn($sub);
						$listing[] = $f;
					}
				}
			}
			return $listing;
		});
		return $folder;
	}

	private function mockUserManager(): IUserManager {
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(function ($uid) {
			if (!isset($this->users[$uid])) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($this->users[$uid]);
			return $user;
		});
		return $um;
	}

	private function service(?AssetSearch $assets = null, ?IJobList $jobs = null): SensitiveContent {
		$folder = $this->mockFolder();
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(fn ($name) => $name === 'sensitive' ? $folder : $this->mockFolder($name));
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$config = $this->createMock(Config::class);
		$config->method('get')->willReturnCallback(fn ($k) => $k === 'sensitive_detection' ? $this->allowed : null);

		$prefs = $this->createMock(UserPrefs::class);
		$prefs->method('get')->willReturnCallback(function ($uid, $k) {
			return $this->prefs[$uid][$k] ?? UserPrefs::PREFS[$k][1];
		});
		$prefs->method('set')->willReturnCallback(function ($uid, $k, $v) {
			$this->prefs[$uid][$k] = $v;
		});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturnCallback(fn ($id) => $id < 100 ? $this->createMock(Node::class) : null);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		return new SensitiveContent(
			$config,
			$prefs,
			$this->createMock(ImmichDb::class),
			$this->createMock(ImmichClient::class),
			$assets ?? $this->createMock(AssetSearch::class),
			$root,
			$this->createMock(IClientService::class),
			$this->createMock(IConfig::class),
			$this->createMock(PrivateArea::class),
			$factory,
			$this->createMock(LoggerInterface::class),
			null,
			$jobs,
			$this->mockUserManager(),
		);
	}

	protected function setUp(): void {
		$this->files = [];
		$this->prefs = [];
		$this->users = [
			'florian' => 'Florian Schneider',
			'tara' => 'Tara Test',
			'bob' => 'Bob User',
		];
		$this->prefs['florian'] = ['sensitive_enabled' => true, 'sensitive_mode' => 'all'];
		$this->prefs['tara'] = ['sensitive_enabled' => true, 'sensitive_mode' => 'all'];
		$this->prefs['bob'] = ['sensitive_enabled' => true, 'sensitive_mode' => 'all'];
	}

	public function testLazyMigrationOfLegacyUserFile(): void {
		$this->files['florian.json'] = json_encode([
			'terms' => [['q' => 'beach', 'min' => 0.65]],
			'svm' => ['w' => [0.2], 'b' => -0.1],
			'learned' => [5, 10],
			'marksChanged' => 1700000000,
			'built' => 1700000000,
		]);

		$s = $this->service();
		$filter = $s->getFilter('florian');

		$this->assertSame('florian', $filter['id']);
		$this->assertSame('florian', $filter['owner']);
		$this->assertSame(['florian' => 'owner'], $filter['members']);
		$this->assertSame([['q' => 'beach', 'min' => 0.65]], $filter['terms']);
		$this->assertSame(['w' => [0.2], 'b' => -0.1], $filter['svm']);
		$this->assertSame([5, 10], $filter['learned']);

		// Verify file was written to filters/florian.json
		$this->assertArrayHasKey('filters/florian.json', $this->files);

		// Second call is idempotent and reads from filter cache/file
		$filter2 = $s->getFilter('florian');
		$this->assertSame($filter, $filter2);

		// Status reflects filter details
		$status = $s->status('florian');
		$this->assertSame('florian', $status['filter']['id']);
		$this->assertSame('florian', $status['filter']['owner']);
		$this->assertSame('Florian Schneider', $status['filter']['ownerName']);
		$this->assertSame('owner', $status['filter']['role']);
	}

	public function testMigrateAllCommandRepairsExistingUsers(): void {
		$this->files['florian.json'] = json_encode(['terms' => []]);
		$this->files['tara.json'] = json_encode(['terms' => []]);

		$s = $this->service();
		$migrated = $s->migrateAll();
		$this->assertSame(2, $migrated);
		$this->assertArrayHasKey('filters/florian.json', $this->files);
		$this->assertArrayHasKey('filters/tara.json', $this->files);

		// Second run does nothing
		$this->assertSame(0, $s->migrateAll());
	}

	public function testInviteAcceptDeclineLeaveFlow(): void {
		$s = $this->service();

		// Florian invites Tara as editor
		$s->inviteMember('florian', 'tara', 'editor');

		// Tara sees pending invitation
		$invitations = $s->invitationsForUser('tara');
		$this->assertCount(1, $invitations);
		$this->assertSame('florian', $invitations[0]['filterId']);
		$this->assertSame('Florian Schneider', $invitations[0]['ownerName']);
		$this->assertSame('editor', $invitations[0]['role']);

		// Tara declines invitation
		$s->declineInvitation('tara', 'florian');
		$this->assertSame([], $s->invitationsForUser('tara'));

		// Florian invites Tara again
		$s->inviteMember('florian', 'tara', 'editor');
		$this->assertCount(1, $s->invitationsForUser('tara'));

		// Tara accepts invitation
		$s->acceptInvitation('tara', 'florian');

		// Tara's active filter preference is now florian
		$this->assertSame('florian', $this->prefs['tara']['sensitive_filter']);
		$this->assertSame('florian', $s->filterId('tara'));
		$this->assertSame('editor', $s->roleInFilter('tara'));

		// Status reflects shared filter
		$statusTara = $s->status('tara');
		$this->assertTrue($statusTara['filter']['shared']);
		$this->assertSame('editor', $statusTara['filter']['role']);
		$this->assertSame('florian', $statusTara['filter']['owner']);
		$this->assertSame('Florian Schneider', $statusTara['filter']['ownerName']);

		// Owner status lists members
		$statusFlorian = $s->status('florian');
		$this->assertTrue($statusFlorian['filter']['shared']);
		$this->assertSame('owner', $statusFlorian['filter']['role']);
		$this->assertCount(2, $statusFlorian['filter']['members']);

		// Tara leaves filter
		$s->leaveFilter('tara');
		$this->assertSame('tara', $this->prefs['tara']['sensitive_filter']);
		$this->assertSame('tara', $s->filterId('tara'));
		$this->assertSame('owner', $s->roleInFilter('tara'));
	}

	public function testOwnerCanUpdateRoleAndRemoveMember(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');

		$this->assertSame('editor', $s->roleInFilter('tara', 'florian'));

		// Change role to user
		$s->updateMemberRole('florian', 'tara', 'user');
		$this->assertSame('user', $s->roleInFilter('tara', 'florian'));

		// Remove member
		$s->removeMember('florian', 'tara');
		$this->assertSame('none', $s->roleInFilter('tara', 'florian'));
	}

	public function testPermissionsEnforced(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'user');
		$s->acceptInvitation('tara', 'florian');

		// Non-owner cannot invite
		$this->expectException(\DomainException::class);
		$s->inviteMember('tara', 'bob', 'editor');
	}

	public function testNonOwnerCannotUpdateRole(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');

		$this->expectException(\DomainException::class);
		$s->updateMemberRole('tara', 'florian', 'user');
	}

	public function testNonOwnerCannotRemoveMember(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');

		$this->expectException(\DomainException::class);
		$s->removeMember('tara', 'florian');
	}

	public function testUserRoleCannotManageTerms(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'user');
		$s->acceptInvitation('tara', 'florian');

		// Tara is role "user", cannot add or remove terms
		$this->expectException(\DomainException::class);
		$s->removeTerm('tara', 'beach');
	}

	public function testPersonalMarksAlwaysOverrideLocally(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');

		// Shared filter has auto-detected file 10 as sensitive
		$this->files['tara.json'] = json_encode([
			'auto' => [10, 20],
			'marks' => [10 => false], // Tara marked 10 as NOT sensitive
		]);

		$flagged = $s->flagged('tara');
		$this->assertArrayNotHasKey(10, $flagged);
		$this->assertArrayHasKey(20, $flagged);

		$items = [
			['fileId' => 10, 'path' => '/p/10.jpg'],
			['fileId' => 20, 'path' => '/p/20.jpg'],
		];
		$applied = $s->apply('tara', $items);
		$this->assertFalse($applied[0]['sensitive'] ?? false);
		$this->assertTrue($applied[1]['sensitive'] ?? false);
	}

	public function testMarksRecordTimestampAndAuthorRole(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');

		$s->mark('tara', ['15' => true]);
		$data = $s->get('tara');

		$this->assertTrue($data['marks'][15]);
		$this->assertGreaterThan(0, $data['markTimes'][15] ?? 0);

		// And filter's modelChanged was bumped because Tara is an editor
		$filter = $s->getFilter('florian');
		$this->assertGreaterThan(0, $filter['modelChanged']);
	}

	public function testCliShareCommands(): void {
		$s = $this->service();
		$um = $this->mockUserManager();

		// Test SensitiveShareCommand
		$cmd = new SensitiveShareCommand($s, $um);
		$tester = new CommandTester($cmd);
		$tester->execute(['owner' => 'florian', 'member' => 'tara', '--role' => 'editor']);
		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString("Shared florian's filter with tara", $tester->getDisplay());
		$this->assertSame('florian', $s->filterId('tara'));
		$this->assertSame('editor', $s->roleInFilter('tara', 'florian'));

		// Test SensitiveCommand share subcommand
		$cmd2 = new SensitiveCommand($s);
		$tester2 = new CommandTester($cmd2);
		$tester2->execute(['user' => ['share', 'florian', 'bob'], '--role' => 'user']);
		$this->assertSame(0, $tester2->getStatusCode());
		$this->assertStringContainsString("Shared florian's filter with bob", $tester2->getDisplay());
		$this->assertSame('florian', $s->filterId('bob'));
		$this->assertSame('user', $s->roleInFilter('bob', 'florian'));
	}

	public function testPooledTrainingExcludesUserRoleAndResolvesConflictNewestWins(): void {
		$s = $this->service();
		$s->inviteMember('florian', 'tara', 'editor');
		$s->acceptInvitation('tara', 'florian');
		$s->inviteMember('florian', 'bob', 'user');
		$s->acceptInvitation('bob', 'florian');

		// Florian marked asset-1 as sensitive (t=100) and asset-2 as sensitive (t=100)
		$this->files['florian.json'] = json_encode([
			'marks' => [1 => true, 2 => true],
			'markTimes' => [1 => 100, 2 => 100],
			'markAssets' => [1 => 'asset-1', 2 => 'asset-2'],
		]);

		// Tara (editor) marked asset-1 as NOT sensitive at newer time (t=200), and asset-3 as sensitive (t=200)
		$this->files['tara.json'] = json_encode([
			'marks' => [1 => false, 3 => true],
			'markTimes' => [1 => 200, 3 => 200],
			'markAssets' => [1 => 'asset-1', 3 => 'asset-3'],
		]);

		// Bob (role: user) marked asset-4 as sensitive (t=300) - must be excluded from filter training
		$this->files['bob.json'] = json_encode([
			'marks' => [4 => true],
			'markTimes' => [4 => 300],
			'markAssets' => [4 => 'asset-4'],
		]);

		// Mock PDO to return alive assets for ANY(?::uuid[]) query in markAssets
		$pdo = $this->createMock(\PDO::class);
		$stmt = $this->createMock(\PDOStatement::class);
		$stmt->method('fetchAll')->willReturn(['asset-1', 'asset-2', 'asset-3', 'asset-4']);
		$pdo->method('prepare')->willReturn($stmt);

		$filter = $s->getFilter('florian');
		$ref = new \ReflectionMethod(SensitiveContent::class, 'trainFilter');
		$ref->setAccessible(true);
		$trained = $ref->invoke($s, $pdo, $filter, 'sig');

		// Learned counts: [positives, negatives]
		// Positives must be 2: asset-2 (Florian) and asset-3 (Tara)
		// Negatives must be 1: asset-1 (Tara's t=200 mark won over Florian's t=100 mark)
		// asset-4 from Bob (role: user) is excluded
		$this->assertSame([2, 1], $trained['learned']);
	}
}
