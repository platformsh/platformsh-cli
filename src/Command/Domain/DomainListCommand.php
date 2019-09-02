<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Domain;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends DomainCommandBase
{
    protected static $defaultName = 'domain:list';

    private $api;
    private $config;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector,
        PropertyFormatter $formatter,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['domains'])
            ->setDescription('Get a list of all domains');

        $definition = $this->getDefinition();
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
    }

    /**
     * Recursively build rows of the domain table.
     *
     * @param Domain[] $tree
     *
     * @return array
     */
    protected function buildDomainRows(array $tree)
    {
        $rows = [];

        foreach ($tree as $domain) {
            $rows[] = [
                'name' => $domain->id,
                'ssl' => $this->formatter->format((bool) $domain['ssl']['has_certificate']),
                'created_at' => $this->formatter->format($domain['created_at'], 'created_at'),
                'updated_at' => $this->formatter->format($domain['updated_at'], 'updated_at'),
            ];
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();
        $executable = $this->config->get('application.executable');

        try {
            $domains = $project->getDomains();
        } catch (ClientException $e) {
            $this->handleApiException($e, $project);
            return 1;
        }

        if (empty($domains)) {
            $this->stdErr->writeln('No domains found for ' . $this->api->getProjectLabel($project) . '.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'Add a domain to the project by running <info>' . $executable . ' domain:add [domain-name]</info>'
            );

            return 1;
        }

        $header = ['name' => 'Name', 'ssl' => 'SSL enabled', 'created_at' => 'Creation date', 'updated_at' => 'Updated date'];
        $defaultColumns = ['name', 'ssl', 'created_at'];
        $rows = $this->buildDomainRows($domains);

        if ($this->table->formatIsMachineReadable()) {
            $this->table->render($rows, $header, $defaultColumns);

            return 0;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Domains on the project %s:',
                $this->api->getProjectLabel($project)
            ));
        }

        $this->table->render($rows, $header, $defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln([
                'To add a new domain, run: <info>' . $executable . ' domain:add [domain-name]</info>',
                'To view a domain, run: <info>' . $executable . ' domain:get [domain-name]</info>',
                'To delete a domain, run: <info>' . $executable . ' domain:delete [domain-name]</info>',
            ]);
        }

        return 0;
    }
}
