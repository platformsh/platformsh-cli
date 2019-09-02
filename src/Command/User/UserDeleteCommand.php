<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserDeleteCommand extends CommandBase
{

    protected static $defaultName = 'user:delete';

    private $activityService;
    private $api;
    private $questionHelper;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Delete a user from the project')
            ->addArgument('email', InputArgument::REQUIRED, "The user's email address");
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addExample('Delete Alice from the project', 'alice@example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $email = $input->getArgument('email');
        $selectedUser = $this->api->loadProjectAccessByEmail($project, $email);
        if (empty($selectedUser)) {
            $this->stdErr->writeln("User not found: <error>$email</error>");
            return 1;
        }

        if ($project->owner === $selectedUser->id) {
            $this->stdErr->writeln(sprintf(
                'The user <error>%s</error> is the owner of the project %s.',
                $email,
                $this->api->getProjectLabel($project, 'error')
            ));
            $this->stdErr->writeln("The project's owner cannot be deleted.");
            return 1;
        }

        if (!$this->questionHelper->confirm("Are you sure you want to delete the user <info>$email</info>?")) {
            return 1;
        }

        $result = $selectedUser->delete();

        $this->stdErr->writeln("User <info>$email</info> deleted");

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        // If the user was deleting themselves from the project, then invalidate
        // the projects cache.
        $myUserId = $this->api->getMyAccount()['id'];
        if ($myUserId === $selectedUser->id) {
            $this->api->clearProjectsCache();
        }

        return 0;
    }
}
