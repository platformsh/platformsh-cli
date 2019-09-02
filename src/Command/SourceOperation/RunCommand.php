<?php

namespace Platformsh\Cli\Command\SourceOperation;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\Variable;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends CommandBase
{
    protected static $defaultName = 'source-operation:run';
    protected $stability = 'BETA';

    private $activityService;
    private $api;
    private $selector;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Selector $selector
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Run a source operation')
            ->addArgument('operation', InputArgument::REQUIRED, 'The operation name')
            ->addOption('variable', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'A variable to set during the operation, in the format <info>type:name=value</info>');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);

        $this->addExample('Run the "update" operation, setting environment variable FOO=bar', 'update --variable env:FOO=bar');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $variables = $this->parseVariables($input->getOption('variable'));
        $this->debug('Parsed variables: ' . json_encode($variables));

        try {
            $result = $selection->getEnvironment()->runSourceOperation(
                $input->getArgument('operation'),
                $variables
            );
        } catch (OperationUnavailableException $e) {
            throw new ApiFeatureMissingException('This project does not support source operations.');
        }

        $success = true;
        if ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $selection->getProject());
        }

        return $success ? 0 : 1;
    }

    /**
     * @param array $variables
     *
     * @return array
     */
    private function parseVariables(array $variables)
    {
        $map = [];
        $variable = new Variable();
        foreach ($variables as $var) {
            list($type, $name, $value) = $variable->parse($var);
            $map[$type][$name] = $value;
        }

        return $map;
    }
}
