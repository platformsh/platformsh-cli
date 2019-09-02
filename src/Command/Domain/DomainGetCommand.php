<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainGetCommand extends DomainCommandBase
{
    protected static $defaultName = 'domain:get';

    private $api;
    private $config;
    private $selector;
    private $table;
    private $formatter;
    private $questionHelper;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        PropertyFormatter $formatter,
        QuestionHelper $questionHelper,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->table = $table;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Show detailed information for a domain')
            ->addArgument('name', InputArgument::OPTIONAL, 'The domain name')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The domain property to view');

        $definition = $this->getDefinition();
        $this->table->configureInput($definition);
        $this->formatter->configureInput($definition);
        $this->selector->addProjectOption($definition);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $domainName = $input->getArgument('name');
        if (empty($domainName)) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The domain name is required.');
                return 1;
            }

            $domains = $project->getDomains();
            $options = [];
            foreach ($domains as $domain) {
                $options[$domain->name] = $domain->name;
            }
            $domainName = $this->questionHelper->choose($options, 'Enter a number to choose a domain:');
        }

        $domain = $project->getDomain($domainName);
        if (!$domain) {
            $this->stdErr->writeln('Domain not found: <error>' . $domainName . '</error>');
            return 1;
        }

        if ($property = $input->getOption('property')) {
            $value = $this->api->getNestedProperty($domain, $property);
            $output->writeln($this->formatter->format($value, $property));

            return 0;
        }

        $values = [];
        $properties = [];
        foreach ($domain->getProperties() as $name => $value) {
            // Hide the deprecated (irrelevant) property 'wildcard'.
            if ($name === 'wildcard') {
                continue;
            }
            $properties[] = $name;
            $values[] = $this->formatter->format($value, $name);
        }
        $this->table->renderSimple($values, $properties);

        $this->stdErr->writeln('');
        $executable = $this->config->get('application.executable');
        $this->stdErr->writeln([
            'To update a domain, run: <info>' . $executable . ' domain:update [domain-name]</info>',
            'To delete a domain, run: <info>' . $executable . ' domain:delete [domain-name]</info>',
        ]);

        return 0;
    }
}
