<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Identifier;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectSetRemoteCommand extends CommandBase
{
    protected static $defaultName = 'project:set-remote';

    private $api;
    private $git;
    private $identifier;
    private $localProject;

    public function __construct(
        Api $api,
        Git $git,
        Identifier $identifier,
        LocalProject $localProject
    ) {
        $this->api = $api;
        $this->git = $git;
        $this->identifier = $identifier;
        $this->localProject = $localProject;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Set the remote project for the current Git repository')
            ->addArgument('project', InputArgument::REQUIRED, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('project');

        $result = $this->identifier->identify($projectId);

        $project = $this->api->getProject($result['projectId'], $result['host']);
        if (!$project) {
            throw new InvalidArgumentException('Specified project not found: ' . $projectId);
        }

        $this->git->ensureInstalled();
        $root = $this->git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->debug('Git repository found: ' . $root);

        $config = $this->localProject->getProjectConfig();
        if (!empty($config['id']) && $config['id'] === $project->id) {
            $this->stdErr->writeln(sprintf(
                'The remote project for this repository is already set as: <info>%s</info>',
                $this->api->getProjectLabel($project)
            ));

            return 0;
        }

        $this->stdErr->writeln(sprintf(
            'Setting the remote project for this repository to: <info>%s</info>',
            $this->api->getProjectLabel($project)
        ));

        $this->localProject->mapDirectory($root, $project);

        return 0;
    }
}
