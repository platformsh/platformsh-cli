<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Util\SslUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateAddCommand extends CommandBase
{
    protected static $defaultName = 'certificate:add';

    private $selector;
    private $activityService;

    public function __construct(Selector $selector, ActivityService $activityService)
    {
        $this->selector = $selector;
        $this->activityService = $activityService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Add an SSL certificate to the project')
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the certificate private key file')
            ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file');
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();

        $certPath = $input->getOption('cert');
        $keyPath = $input->getOption('key');
        $chainPaths = $input->getOption('chain');
        if (!isset($certPath, $keyPath)) {
            $this->stdErr->writeln('The --cert and --key options are required');
            return 1;
        }

        $options = (new SslUtil())->validate($certPath, $keyPath, $chainPaths);

        $result = $project->addCertificate($options['certificate'], $options['key'], $options['chain']);

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
