<?php
namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class ProjectSetRemoteCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('project:set-remote')
            ->setDescription('Set the remote project for the current Git repository')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
        $this->addExample('Unset the remote project for this repository', '-');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('project');
        $unset = false;
        if ($projectId === '-') {
            $unset = true;
            $projectId = null;
        }

        if ($projectId) {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
        }

        if (!$unset) {
            $project = $this->selectProject($projectId, null, false);
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->ensureInstalled();
        $root = $git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->debug('Git repository found: ' . $root);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if ($unset) {
            $configFilename = $root . DIRECTORY_SEPARATOR . $this->config()->get('local.project_config');
            if (!\file_exists($configFilename)) {
                $configFilename = null;
            }
            $currentUrl = $git->getConfig(
                sprintf('remote.%s.url', $this->config()->get('detection.git_remote_name')),
                $root
            );
            if (!$currentUrl && !$configFilename) {
                $this->stdErr->writeln('This repository is not mapped to a remote project.');
                return 0;
            }
            $this->stdErr->writeln('Unsetting the remote project for this repository...');
            if ($configFilename) {
                $this->stdErr->writeln(sprintf('Removing local project config file: <info>%s</info>', $configFilename));
            }
            if ($currentUrl) {
                $this->stdErr->writeln(sprintf('Removing Git remote <info>%s</info>: %s', $this->config()->get('detection.git_remote_name'), $currentUrl));
            }
            if (!$questionHelper->confirm('Are you sure?')) {
                return 1;
            }
            if ($currentUrl) {
                $git->execute(
                    ['remote', 'rm', $this->config()->get('detection.git_remote_name')],
                    $root,
                    true
                );
            }
            if ($configFilename) {
                (new Filesystem())->remove($configFilename);
            }
            $this->stdErr->writeln('This repository is no longer mapped to a project.');
            return 0;
        }

        try {
            $currentProject = $this->getCurrentProject();
        } catch (ProjectNotFoundException $e) {
            $currentProject = false;
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                $currentProject = false;
            } else {
                throw $e;
            }
        }
        if ($currentProject && $currentProject->id === $project->id) {
            $this->stdErr->writeln(sprintf(
                'The remote project for this repository is already set as: %s',
                $this->api()->getProjectLabel($currentProject)
            ));

            return 0;
        } elseif ($currentProject) {
            $this->stdErr->writeln(sprintf(
                'Changing the remote project for this repository from %s to %s',
                $this->api()->getProjectLabel($currentProject),
                $this->api()->getProjectLabel($project)
            ));
        } else {
            $this->stdErr->writeln(sprintf(
                'Setting the remote project for this repository to: %s',
                $this->api()->getProjectLabel($project)
            ));
        }

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->mapDirectory($root, $project);

        $this->stdErr->writeln(sprintf(
            'The remote project for this repository is now set to: %s',
            $this->api()->getProjectLabel($project)
        ));

        if ($input->isInteractive()) {
            $currentBranch = $git->getCurrentBranch($root);
            $currentEnvironment = $currentBranch ? $this->api()->getEnvironment($currentBranch, $project) : false;
            if ($currentBranch !== false && $currentEnvironment && $currentEnvironment->has_code) {
                $headSha = $git->execute(['rev-parse', '--verify', 'HEAD'], $root);
                if ($currentEnvironment->head_commit === $headSha) {
                    $this->stdErr->writeln(sprintf("\nThe local branch <info>%s</info> is up to date.", $currentBranch));
                } elseif ($questionHelper->confirm("\nDo you want to pull code from the project?")) {
                    $success = $git->pull($project->getGitUrl(), $currentEnvironment->id, $root, false);

                    return $success ? 0 : 1;
                }
            }
        }

        return 0;
    }
}
