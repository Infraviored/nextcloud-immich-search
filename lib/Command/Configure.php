<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Command;

use OCA\ImmichSearch\AppInfo\Application;
use OCA\ImmichSearch\Service\Config;
use OCP\IConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ immich_search:configure florian --url http://192.168.178.2:2283 --key-stdin < api-key
 * occ immich_search:configure --default-url http://192.168.178.2:2283
 * The key is read from stdin so it never shows up in shell history or process lists.
 */
class Configure extends Command {
    public function __construct(
        private Config $config,
        private IConfig $appConfig,
        private IUserManager $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('immich_search:configure')
            ->setDescription('Immich-Verbindung für einen Benutzer oder die Standard-Adresse setzen')
            ->addArgument('user', InputArgument::OPTIONAL, 'Nextcloud-Benutzer')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Immich-Adresse für diesen Benutzer')
            ->addOption('key-stdin', null, InputOption::VALUE_NONE, 'API-Schlüssel von stdin lesen')
            ->addOption('default-url', null, InputOption::VALUE_REQUIRED, 'Immich-Adresse für alle ohne eigene');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (($default = $input->getOption('default-url')) !== null) {
            $this->appConfig->setAppValue(Application::APP_ID, 'default_url', rtrim($default, '/'));
            $output->writeln('Standard-Adresse gesetzt');
        }
        $uid = $input->getArgument('user');
        if ($uid === null) {
            return self::SUCCESS;
        }
        if (!$this->users->userExists($uid)) {
            $output->writeln('<error>Benutzer gibt es nicht</error>');
            return self::FAILURE;
        }
        if (($url = $input->getOption('url')) !== null) {
            $this->config->setUrl($uid, $url);
            $output->writeln('Adresse gesetzt');
        }
        if ($input->getOption('key-stdin')) {
            $key = trim((string)stream_get_contents(STDIN));
            if ($key === '') {
                $output->writeln('<error>Kein Schlüssel auf stdin</error>');
                return self::FAILURE;
            }
            $this->config->setApiKey($uid, $key);
            $output->writeln('API-Schlüssel gespeichert');
        }
        $output->writeln($this->config->isConfigured($uid) ? 'Eingerichtet' : 'Noch unvollständig');
        return self::SUCCESS;
    }
}
