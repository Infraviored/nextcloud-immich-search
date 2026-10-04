<?php
declare(strict_types=1);

namespace OCA\ImmichSearch\Command;

use OCA\ImmichSearch\Service\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ immich_search:configure --url http://immich:2283 --key-stdin < api-key
 * The key is read from stdin so it never shows up in shell history or process lists.
 */
class Configure extends Command {
    public function __construct(
        private Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('immich_search:configure')
            ->setDescription('Set the Immich connection used by photo search')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Immich base URL, e.g. http://immich:2283')
            ->addOption('key-stdin', null, InputOption::VALUE_NONE, 'Read the API key from stdin')
            ->addOption('path-pattern', null, InputOption::VALUE_REQUIRED,
                'Where Immich sees a user\'s files, default ' . Config::DEFAULT_PATH_PATTERN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            if (($url = $input->getOption('url')) !== null) {
                $this->config->setUrl($url);
                $output->writeln('URL set');
            }
            if (($pattern = $input->getOption('path-pattern')) !== null) {
                $this->config->setPathPattern($pattern);
                $output->writeln('Path pattern set');
            }
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>Invalid ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
        if ($input->getOption('key-stdin')) {
            $key = trim((string)stream_get_contents(STDIN));
            if ($key === '') {
                $output->writeln('<error>No key on stdin</error>');
                return self::FAILURE;
            }
            $this->config->setApiKey($key);
            $output->writeln('API key stored');
        }
        $output->writeln(sprintf('%s, path pattern %s', $this->config->isConfigured() ? 'Configured' : 'Not configured yet', $this->config->getPathPattern()));
        return self::SUCCESS;
    }
}
