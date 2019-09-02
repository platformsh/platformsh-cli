<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDeleteCommand extends CommandBase
{
    protected static $defaultName = 'project:delete';

    private $api;
    private $questionHelper;
    private $selector;

    public function __construct(Api $api, QuestionHelper $questionHelper, Selector $selector) {
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Delete a project')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->selector->addProjectOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($projectId = $input->getArgument('project')) {
            if ($input->getOption('project')) {
                throw new ConsoleInvalidArgumentException(
                    'You cannot use both the <project> argument and the --project option'
                );
            }
            $input->setOption('project', $projectId);
        }
        $project = $this->selector->getSelection($input)->getProject();

        $confirmQuestionLines = [
            'You are about to delete the project:',
            '  ' . $this->api->getProjectLabel($project, 'comment'),
            '',
            ' * This action is <options=bold>irreversible</>.',
            ' * Your site will no longer be accessible.',
            ' * All data associated with this project will be deleted, including backups.',
            ' * You will be charged at the end of the month for any remaining project costs.',
            '',
            'Are you sure you want to delete this project?'
        ];
        if (!$this->questionHelper->confirm(implode("\n", $confirmQuestionLines), false)) {
            return 1;
        }

        $title = $project->title;
        if ($input->isInteractive() && strlen($title)) {
            $confirmName = $this->questionHelper->askInput('Type the project title to confirm');
            if ($confirmName !== $title) {
                $this->stdErr->writeln('Incorrect project title (expected: ' . $title . ')');
                return 1;
            }
        }

        $subscriptionId = $project->getSubscriptionId();
        $subscription = $this->api->getClient()->getSubscription($subscriptionId);
        if (!$subscription) {
            throw new \RuntimeException('Subscription not found: ' . $subscriptionId);
        }

        try {
            $subscription->delete();
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response !== null && $response->getStatusCode() === 403) {
                if ($project->owner !== $this->api->getMyAccount()['id']) {
                    $this->stdErr->writeln("Only the project's owner can delete it.");
                    return 1;
                }
            }
            throw $e;
        }

        $this->api->clearProjectsCache();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The project ' . $this->api->getProjectLabel($project) . ' was deleted.');
        return 0;
    }
}
