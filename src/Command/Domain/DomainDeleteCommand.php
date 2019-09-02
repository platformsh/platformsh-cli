<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainDeleteCommand extends CommandBase
{
    protected static $defaultName = 'domain:delete';

    private $activityService;
    private $selector;
    private $questionHelper;

    public function __construct(Selector $selector, ActivityService $activityService, QuestionHelper $questionHelper)
    {
        $this->selector = $selector;
        $this->activityService = $activityService;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Delete a domain from the project')
            ->addArgument('name', InputArgument::REQUIRED, 'The domain name');
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addExample('Delete the domain example.com', 'example.com');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $name = $input->getArgument('name');

        $domain = $project->getDomain($name);

        if (!$domain) {
            $this->stdErr->writeln("Domain not found: <error>$name</error>");
            return 1;
        }

        if (!$this->questionHelper->confirm("Are you sure you want to delete the domain <info>$name</info>?")) {
            return 1;
        }

        $result = $domain->delete();

        $this->stdErr->writeln("The domain <info>$name</info> has been deleted.");

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
