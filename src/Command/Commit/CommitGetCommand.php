<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CommitGetCommand extends CommandBase
{
    public static $defaultName = 'commit:get';

    private $gitDataApi;
    private $propertyFormatter;
    private $selector;

    public function __construct(
        GitDataApi $gitDataApi,
        PropertyFormatter $propertyFormatter,
        Selector $selector
    ) {
        $this->gitDataApi = $gitDataApi;
        $this->propertyFormatter = $propertyFormatter;
        $this->selector = $selector;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Show commit details')
            ->addArgument('commit', InputArgument::OPTIONAL, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP, 'HEAD')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The commit property to display.');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->propertyFormatter->configureInput($definition);

        $this->addExample('Display the current commit on the environment');
        $this->addExample('Display the previous commit', 'HEAD~');
        $this->addExample('Display the 3rd commit before the current one', 'HEAD~3');
        $this->addExample('Display the email address of the last commit author', '-P author.email');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $commitSha = $input->getArgument('commit');
        $commit = $this->gitDataApi->getCommit($selection->getEnvironment(), $commitSha);
        if (!$commit) {
            if ($commitSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $commitSha . '</error>');
            } else {
                $this->stdErr->writeln('Commit not found.');
            }

            return 1;
        }

        $this->propertyFormatter->displayData($output, $commit->getProperties(), $input->getOption('property'));

        return 0;
    }
}
