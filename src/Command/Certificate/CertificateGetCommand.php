<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateGetCommand extends CommandBase
{
    protected static $defaultName = 'certificate:get';

    private $api;
    private $selector;
    private $formatter;

    public function __construct(Api $api, Selector $selector, PropertyFormatter $formatter)
    {
        $this->api = $api;
        $this->selector = $selector;
        $this->formatter = $formatter;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('View a certificate')
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to view');

        $definition = $this->getDefinition();
        $this->formatter->configureInput($definition);
        $this->selector->addProjectOption($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $id = $input->getArgument('id');
        $cert = $project->getCertificate($id);
        if (!$cert) {
            try {
                $cert = $this->api->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $this->formatter->displayData($output, $cert->getProperties(), $input->getOption('property'));

        return 0;
    }
}
