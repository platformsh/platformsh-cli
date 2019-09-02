<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserListCommand extends CommandBase
{

    protected static $defaultName = 'user:list';

    private $api;
    private $config;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['users'])
            ->setDescription('List project users');
        $this->table->configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $rows = [];
        $i = 0;
        foreach ($this->api->getProjectAccesses($project) as $projectAccess) {
            $account = $this->api->getAccount($projectAccess);
            $role = $projectAccess->role;
            $weight = $i++;
            if ($project->owner === $projectAccess->id) {
                $weight = -1;
                if (!$this->table->formatIsMachineReadable()) {
                    $role .= ' (owner)';
                }
            }
            $rows[$weight] = ['email' => $account['email'], $account['display_name'], 'role' => $role, $projectAccess->id];
        }

        ksort($rows);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Users on the project %s:',
                $this->api->getProjectLabel($project)
            ));
        }

        $this->table->render(array_values($rows), ['email' => 'Email address', 'Name', 'role' => 'Project role', 'ID']);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln("To add a new user to the project, run: <info>$executable user:add [email]</info>");
            $this->stdErr->writeln('');
            $this->stdErr->writeln("To view a user's role(s), run: <info>$executable user:get [email]</info>");
            $this->stdErr->writeln("To change a user's role(s), run: <info>$executable user:update [email]</info>");
        }

        return 0;
    }
}
