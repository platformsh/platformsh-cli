<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Certificate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateListCommand extends CommandBase
{

    protected static $defaultName = 'certificate:list';

    private $api;
    private $config;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['certificates', 'certs'])
            ->setDescription('List project certificates');
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by domain name (case-insensitive search)');
        $this->addOption('exclude-domain', null, InputOption::VALUE_REQUIRED, 'Exclude certificates, matching by domain name (case-insensitive search)');
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Filter by issuer');
        $this->addOption('only-auto', null, InputOption::VALUE_NONE, 'Show only auto-provisioned certificates');
        $this->addOption('no-auto', null, InputOption::VALUE_NONE, 'Show only manually added certificates');
        $this->addOption('ignore-expiry', null, InputOption::VALUE_NONE, 'Show both expired and non-expired certificates');
        $this->addOption('only-expired', null, InputOption::VALUE_NONE, 'Show only expired certificates');
        $this->addOption('no-expired', null, InputOption::VALUE_NONE, 'Show only non-expired certificates (default)');
        $this->addOption('pipe-domains', null, InputOption::VALUE_NONE, 'Only return a list of domain names covered by the certificates');

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->table->configureInput($definition);
        $this->selector->addProjectOption($definition);
        $this->addExample('Output a list of domains covered by valid certificates', '--pipe-domains --no-expired');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        // Set --no-expired by default, if --ignore-expiry and --only-expired
        // are not supplied.
        if (!$input->getOption('ignore-expiry') && !$input->getOption('only-expired')) {
            $input->setOption('no-expired', true);
        }

        $filterOptions = ['domain', 'exclude-domain', 'issuer', 'only-auto', 'no-auto', 'only-expired', 'no-expired'];
        $filters = array_filter(array_intersect_key($input->getOptions(), array_flip($filterOptions)));

        $certs = $project->getCertificates();

        $this->filterCerts($certs, $filters);

        if (!empty($filters) && !$input->getOption('pipe-domains')) {
            $filtersUsed = '<comment>--'
                . implode('</comment>, <comment>--', array_keys($filters))
                . '</comment>';
            $this->stdErr->writeln(sprintf('Filters in use: %s', $filtersUsed));
            $this->stdErr->writeln('');
        }

        if (empty($certs)) {
            $this->stdErr->writeln("No certificates found");

            return 0;
        }

        if ($input->getOption('pipe-domains')) {
            foreach ($certs as $cert) {
                foreach ($cert->domains as $domain) {
                    $output->writeln($domain);
                }
            }

            return 0;
        }

        $header = ['ID', 'domains' => 'Domain(s)', 'Created', 'Expires', 'Issuer'];
        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = [
                $cert->id,
                'domains' => implode("\n", $cert->domains),
                $this->formatter->format($cert->created_at, 'created_at'),
                $this->formatter->format($cert->expires_at, 'expires_at'),
                $this->getCertificateIssuerByAlias($cert, 'commonName') ?: '',
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf('Certificates for the project <info>%s</info>:', $this->api->getProjectLabel($project)));
        }

        $this->table->render($rows, $header);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view a single certificate, run: <info>%s certificate:get <id></info>',
                $this->config->get('application.executable')
            ));
        }

        return 0;
    }

    protected function filterCerts(array &$certs, array $filters)
    {
        foreach ($filters as $filter => $value) {
            switch ($filter) {
                case 'domain':
                case 'exclude-domain':
                    $include = $filter === 'domain';
                    $certs = array_filter($certs, function (Certificate $cert) use ($value, $include) {
                        foreach ($cert->domains as $domain) {
                            if (stripos($domain, $value) !== false) {
                                return $include;
                            }
                        }

                        return !$include;
                    });
                    break;

                case 'issuer':
                    $certs = array_filter($certs, function (Certificate $cert) use ($value) {
                        foreach ($cert->issuer as $issuer) {
                            if (isset($issuer['value']) && $issuer['value'] === $value) {
                                return true;
                            }
                        }

                        return false;
                    });
                    break;

                case 'only-auto':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return (bool) $cert->is_provisioned;
                    });
                    break;

                case 'no-auto':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return !$cert->is_provisioned;
                    });
                    break;

                case 'no-expired':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return !$this->isExpired($cert);
                    });
                    break;

                case 'only-expired':
                    $certs = array_filter($certs, function (Certificate $cert) {
                        return $this->isExpired($cert);
                    });
                    break;
            }
        }
    }

    /**
     * Check if a certificate has expired.
     *
     * @param \Platformsh\Client\Model\Certificate $cert
     *
     * @return bool
     */
    private function isExpired(Certificate $cert)
    {
        return time() >= strtotime($cert->expires_at);
    }

    /**
     * @param \Platformsh\Client\Model\Certificate $cert
     * @param string                               $alias
     *
     * @return string|bool
     */
    protected function getCertificateIssuerByAlias(Certificate $cert, $alias) {
        foreach ($cert->issuer as $issuer) {
            if (isset($issuer['alias'], $issuer['value']) && $issuer['alias'] === $alias) {
                return $issuer['value'];
            }
        }

        return false;
    }
}
