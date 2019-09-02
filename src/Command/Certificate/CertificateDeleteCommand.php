<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateDeleteCommand extends CommandBase
{
    protected static $defaultName = 'certificate:delete';

    private $activityService;
    private $api;
    private $selector;
    private $questionHelper;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Selector $selector,
        QuestionHelper $questionHelper
    ) {
        $this->api = $api;
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
        $this->setDescription('Delete a certificate from the project')
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityService->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->selector->getSelection($input)->getProject();
        $id = $input->getArgument('id');

        $certificate = $project->getCertificate($id);
        if (!$certificate) {
            try {
                $certificate = $this->api->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to delete the certificate <info>%s</info>?', $certificate->id))) {
            return 1;
        }

        try {
            $result = $certificate->delete();
        } catch (BadResponseException $e) {
            if (($response = $e->getResponse()) && $response->getStatusCode() === 403 && $certificate->is_provisioned) {
                $this->stdErr->writeln(sprintf('The certificate <error>%s</error> is automatically provisioned; it cannot be deleted.', $certificate->id));
                return 1;
            }

            throw $e;
        }

        $this->stdErr->writeln(sprintf('The certificate <info>%s</info> has been deleted.', $certificate->id));

        if ($this->activityService->shouldWait($input)) {
            $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
