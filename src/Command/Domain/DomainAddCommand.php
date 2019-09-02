<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends DomainCommandBase
{
    protected static $defaultName = 'domain:add';

    private $activityService;
    private $selector;

    public function __construct(Selector $selector, ActivityService $activityService)
    {
        $this->selector = $selector;
        $this->activityService = $activityService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Add a new domain to the project');
        $this->addDomainOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addExample('Add the domain example.com', 'example.com');
        $this->addExample(
            'Add the domain secure.example.com with SSL enabled',
            'secure.example.com --cert secure-example-com.crt --key secure-example-com.key'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        if (!$this->validateDomainInput($input)) {
            return 1;
        }

        try {
            $this->stdErr->writeln("Adding the domain <info>{$this->domainName}</info>");
            $result = $project->addDomain($this->domainName, $this->sslOptions);
        } catch (ClientException $e) {
            // Catch 409 Conflict errors.
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() === 409) {
                $this->stdErr->writeln("The domain <error>{$this->domainName}</error> already exists on the project.");
                $this->stdErr->writeln("Use <info>domain:delete</info> to delete an existing domain");
            } else {
                $this->handleApiException($e, $project);
            }

            return 1;
        }

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
