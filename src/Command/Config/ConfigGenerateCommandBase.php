<?php

/**
 * @file
 * Base class for a command that configures a project using a set of templates.
 */

namespace Platformsh\Cli\Command\Config;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;

abstract class ConfigGenerateCommandBase extends CommandBase implements ConfigGenerateInterface
{
    /** {@inheritdoc} */
    protected $hiddenInList = true;

    /** @var string|null */
    protected $appRoot;

    /** @var array */
    protected $parameters = [];

    /** @var string|null */
    private $repoRoot;

    /** @var Form */
    private $form;

    /** @var \Twig_Environment */
    private $engine;

    /**
     * {@inheritdoc}
     */
    abstract public function getKey();

    /**
     * {@inheritdoc}
     */
    abstract public function getLabel();

    /**
     * {@inheritdoc}
     */
    abstract public function getFields();

    /**
     * @param Field[] $fields
     *
     * @return Field[]
     */
    private function addGlobalFields(array $fields)
    {
        $pre = [];
        $pre['application_name'] = new Field('Application name', [
            'optionName' => 'name',
            'default' => 'app',
            'validator' => function ($value) {
                return preg_match('/^[a-z0-9-]+$/', $value)
                    ? true
                    : 'The application name can only consist of lower-case letters and numbers.';
            },
        ]);
        $pre['application_disk'] = new Field('Application disk size (MB)', [
            'optionName' => 'disk',
            'default' => !empty($currentConfig['disk']) ? $currentConfig['disk'] : 2048,
            'validator' => function ($value) {
                return is_numeric($value) && $value >= 512 && $value < 512000;
            },
            'normalizer' => 'intval',
        ]);

        $post = [];
        $post['application_subdir'] = new BooleanField('Create the application in a subdirectory', [
            'optionName' => 'subdir',
            'default' => false,
        ]);
        $post['application_root'] = new Field('Directory name', [
            'conditions' => ['application_subdir' => true],
            'optionName' => 'subdir-name',
            'defaultCallback' => function (array $previousValues) {
                return $previousValues['application_name'];
            },
            'normalizer' => function ($value) {
                return trim($value, '/');
            },
        ]);

        return array_merge($pre, $fields, $post);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('config:generate:' . $this->getKey());
        $this->setDescription('Configure a project with the ' . $this->getLabel() . ' template');
        $this->addOption('no-overwrite', null, InputOption::VALUE_NONE, 'Do not overwrite any files');
        $this->form = Form::fromArray($this->addGlobalFields($this->getFields()));
        $this->form->configureInputDefinition($this->getDefinition());
    }

    /**
     * Determine where we are building this template.
     *
     * @todo Make this default to the current directory if no git
     *
     * @return string|false
     */
    protected function getRepositoryRoot()
    {
        if (isset($this->repoRoot)) {
            return $this->repoRoot;
        }
        $this->repoRoot = $this->getProjectRoot();
        if (!$this->repoRoot) {
            /** @var \Platformsh\Cli\Service\Git $git */
            $git = $this->getService('git');
            $this->repoRoot = $git->getRoot();
        }

        return $this->repoRoot;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repoRoot = $this->getRepositoryRoot();
        if (!$repoRoot) {
            throw new RootNotFoundException('Repository not found. This can only be run in a project directory or Git repository.');
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $this->parameters = $this->form->resolveOptions($input, $output, $questionHelper);

        $this->appRoot = isset($this->parameters['application_root'])
            ? $repoRoot . '/' . $this->parameters['application_root']
            : $repoRoot;
        unset($this->parameters['application_root'], $this->parameters['application_subdir']);

        $this->parameters += ['services' => [], 'relationships' => [], 'crons' => []];

        $this->alterParameters();

        // These parameter values lose their top level key when used in a template.
        $nested_keys = ['relationships', 'crons'];
        foreach ($nested_keys as $key) {
            if (array_key_exists($key, $this->parameters) && !empty($this->parameters[$key])) {
                $store = $this->parameters[$key];
                unset($this->parameters[$key]);
                $this->parameters[$key][$key] = $store;
            }
        }

        $noOverwrite = $input->getOption('no-overwrite');
        foreach ($this->getTemplateTypes() as $templateType => $destination) {
            $template = $this->getTemplate($templateType);
            $content = $this->renderTemplate($template, $this->parameters);
            $destinationAbsolute = $this->appRoot . '/' . $destination;
            if (file_exists($destinationAbsolute)) {
                $this->stdErr->write(sprintf('The file <comment>%s</comment> already exists.', $destination));
                if ($noOverwrite) {
                    $this->stdErr->writeln('');
                    continue;
                } elseif (!$questionHelper->confirm(' Overwrite?')) {
                    continue;
                }
            }
            $this->stdErr->writeln(sprintf('Writing file <info>%s</info>', $destination));
            $this->dumpFile($destinationAbsolute, $content);
        }

        return 0;
    }

    /**
     * Wraps Symfony Filesystem's dumpFile() to try making a directory writable.
     *
     * @param string $filename
     * @param string $content
     */
    protected function dumpFile($filename, $content)
    {
        $fs = new Filesystem();

        $dir = dirname($filename);
        if (is_dir($dir) && !is_writable($dir)) {
            $fs->chmod($dir, 0700);
        }

        $fs->dumpFile($filename, $content);
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        // Override this to modify parameters.
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateTypes()
    {
        return [
            'app.yaml' => $this->config()->get('service.app_config_file'),
            'routes.yaml' => $this->config()->get('service.project_config_dir') . '/routes.yaml',
            'services.yaml' => $this->config()->get('service.project_config_dir') . '/services.yaml',
        ];
    }

    /**
     * Render a template file.
     *
     * @param string $template
     * @param array  $parameters
     *
     * @return string
     */
    protected function renderTemplate($template, array $parameters)
    {
        if (!$this->engine) {
            /** @var \Doctrine\Common\Cache\CacheProvider $cache */
            $cache = $this->getService('cache');
            $options = [
                'debug' => true,
                'cache' => false,
                'strict_variables' => false,
                'autoescape' => false,
            ];
            $this->engine = new \Twig_Environment(new TemplateLoader(CLI_ROOT . '/resources/templates', $cache), $options);
            $dumper = new Dumper();
            $this->engine->addFilter('yaml', new \Twig_SimpleFilter('yaml', function ($input) use ($dumper) {
                return $dumper->dump($input, 5);
            }));
        }

        return $this->engine->render($template, $parameters);
    }

    /**
     * Get a template.
     *
     * @param string $type The template type (e.g. 'app', 'routes', or
     *                     'services').
     *
     * @return string The template URL or filename.
     */
    protected function getTemplate($type)
    {
        $key = $this->getKey();
        $path = CLI_ROOT . '/resources/templates';
        $candidates = [
            "$key/$type.twig",
            "$type.twig",
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($path . '/' . $candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException("No template found for type $type");
    }
}
