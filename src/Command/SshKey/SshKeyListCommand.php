<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyListCommand extends CommandBase
{
    protected static $defaultName = 'ssh-key:list';

    private $api;
    private $config;
    private $table;

    public function __construct(Api $api, Config $config, Table $table)
    {
        $this->api = $api;
        $this->config = $config;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['ssh-keys'])
            ->setDescription('Get a list of SSH keys in your account');
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $keys = $this->api->getClient()
                     ->getSshKeys();

        if (empty($keys)) {
            $this->stdErr->writeln(sprintf(
                'You do not yet have any SSH public keys in your %s account.',
                $this->config->get('service.name')
            ));
        } else {
            $headers = ['ID', 'Title', 'Fingerprint'];
            $rows = [];
            foreach ($keys as $key) {
                $rows[] = [$key['key_id'], $key['title'], $key['fingerprint']];
            }
            if ($this->table->formatIsMachineReadable()) {
                $this->table->render($rows, $headers);

                return 0;
            }

            $this->stdErr->writeln("Your SSH keys are:");
            $this->table->render($rows, $headers);
        }

        $this->stdErr->writeln('');

        $executable = $this->config->get('application.executable');
        $this->stdErr->writeln("Add a new SSH key with: <info>$executable ssh-key:add</info>");
        $this->stdErr->writeln("Delete an SSH key with: <info>$executable ssh-key:delete [id]</info>");

        return !empty($keys) ? 0 : 1;
    }
}
