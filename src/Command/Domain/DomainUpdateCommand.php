<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainUpdateCommand extends DomainCommandBase
{
    protected static $defaultName = 'domain:update';

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
        $this->setDescription('Update a domain');
        $this->addDomainOptions();
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
        $this->addExample(
            'Update the certificate for the domain example.com',
            'example.com --cert secure-example-com.crt --key secure-example-com.key'
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

        $domain = $project->getDomain($this->domainName);
        if (!$domain) {
            $this->stdErr->writeln('Domain not found: <error>' . $this->domainName . '</error>');
            return 1;
        }

        $needsUpdate = false;
        foreach (['key' => '', 'certificate' => '', 'chain' => []] as $option => $default) {
            if (empty($this->sslOptions[$option])) {
                $this->sslOptions[$option] = $domain->ssl[$option] ?: $default;
            } elseif ($this->sslOptions[$option] != $domain->ssl[$option]) {
                $needsUpdate = true;
            }
        }
        if (!$needsUpdate) {
            $this->stdErr->writeln('There is nothing to update.');

            return 0;
        }

        $this->stdErr->writeln('Updating the domain <info>' . $this->domainName . '</info>');

        $result = $domain->update(['ssl' => $this->sslOptions]);

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
