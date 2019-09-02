<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Client\Exception\GitObjectTypeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CatCommand extends CommandBase
{
    protected static $defaultName = 'repo:cat'; // 🐱

    private $config;
    private $gitDataApi;
    private $selector;

    public function __construct(
        Config $config,
        GitDataApi $gitDataApi,
        Selector $selector
    ) {
        $this->config = $config;
        $this->gitDataApi = $gitDataApi;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Read a file in the project repository')
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file')
            ->addOption('commit', 'c', InputOption::VALUE_REQUIRED, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP);

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);

        $this->addExample(
            'Read the services configuration file',
            $this->config->get('service.project_config_dir') . '/services.yaml'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();
        $path = $input->getArgument('path');
        try {
            $content = $this->gitDataApi->readFile($path, $environment, $input->getOption('commit'));
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf(
                'To list directory contents, run: <comment>%s repo:ls [path]</comment>',
                $this->config->get('application.executable')
            ));

            return 3;
        }
        if ($content === false) {
            $this->stdErr->writeln(sprintf('File not found: <error>%s</error>', $path));

            return 2;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
