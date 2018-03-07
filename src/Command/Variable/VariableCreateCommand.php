<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableCreateCommand extends VariableCommandBase
{
    /** @var Form */
    private $form;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:create')
            ->setDescription('Create a variable')
            ->addArgument('name', InputArgument::OPTIONAL, 'The variable name');
        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        if ($input->getArgument('name')) {
            if ($input->getOption('name')) {
                $this->stdErr->writeln('You cannot use both the <error>name</error> argument and <error>--name</error> option.');

                return 1;
            }
            $input->setOption('name', $input->getArgument('name'));
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $values = $this->form->resolveOptions($input, $output, $questionHelper);

        if (isset($values['prefix']) && isset($values['name'])) {
            if ($values['prefix'] !== 'none') {
                $values['name'] = rtrim($values['prefix'], ':') . ':' .  $values['name'];
            }
            unset($values['prefix']);
        }

        if (isset($values['environment'])) {
            if (!$this->hasSelectedEnvironment()) {
                $this->selectEnvironment($values['environment']);
            }
            unset($values['environment']);
        }

        $level = $values['level'];
        unset($values['level']);

        $id = $values['name'];

        switch ($level) {
            case 'environment':
                $environment = $this->getSelectedEnvironment();
                if ($environment->getVariable($id)) {
                    $this->stdErr->writeln(sprintf(
                        'The variable <error>%s</error> already exists on the environment <error>%s</error>',
                        $id,
                        $environment->id
                    ));

                    return 1;
                }
                $this->stdErr->writeln(sprintf(
                    'Creating variable <info>%s</info> on the environment <info>%s</info>', $id, $environment->id));
                $result = Variable::create($values, $environment->getLink('#manage-variables'), $this->api()->getHttpClient());
                break;

            case 'project':
                $project = $this->getSelectedProject();
                if ($project->getVariable($id)) {
                    $this->stdErr->writeln(sprintf(
                        'The variable <error>%s</error> already exists on the project %s',
                        $id,
                        $this->api()->getProjectLabel($project, 'error')
                    ));

                    return 1;
                }
                $this->stdErr->writeln(sprintf(
                    'Creating variable <info>%s</info> on the project %s',
                    $id,
                    $this->api()->getProjectLabel($project, 'info')
                ));

                $this->stdErr->writeln('Creating project-level variable: <info>' . $values['name'] . '</info>');
                $result = ProjectLevelVariable::create($values, $project->getUri() . '/variables', $this->api()->getHttpClient());
                break;

            default:
                throw new \RuntimeException('Invalid level: ' . $level);
        }

        $this->displayVariable($result->getEntity());

        $success = true;
        if (!$result->countActivities()) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
