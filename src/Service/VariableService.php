<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\Selection;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\ApiResourceBase;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class VariableService
{
    const LEVEL_PROJECT = 'project';
    const LEVEL_ENVIRONMENT = 'environment';

    private $api;
    private $config;
    private $formatter;
    private $stdErr;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        OutputInterface $output,
        PropertyFormatter $formatter,
        Table $table
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->table = $table;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public function escapeShellArg($str)
    {
        return (new ArgvInput(['example']))->escapeToken($str);
    }

    /**
     * Add the --level option.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public function addLevelOption(InputDefinition $definition)
    {
        $definition->addOption(new InputOption('level', 'l', InputOption::VALUE_REQUIRED, "The variable level ('project', 'environment', 'p' or 'e')"));
    }

    /**
     * Get the requested variable level.
     *
     * @param InputInterface $input
     *
     * @return string|null
     */
    public function getRequestedLevel(InputInterface $input)
    {
        $str = $input->getOption('level');
        if (empty($str)) {
            return null;
        }
        foreach ([self::LEVEL_PROJECT, self::LEVEL_ENVIRONMENT] as $validLevel) {
            if (stripos($validLevel, $str) === 0) {
                return $validLevel;
            }
        }
        throw new InvalidArgumentException('Invalid level: ' . $str);
    }

    /**
     * Finds an existing variable by name.
     *
     * @param Selection   $selection
     * @param string      $name
     * @param string|null $level
     * @param bool        $messages Whether to print error messages to
     *                              $this->stdErr if the variable is not found.
     *
     * @return \Platformsh\Client\Model\ProjectLevelVariable|\Platformsh\Client\Model\Variable|false
     */
    public function getExistingVariable(Selection $selection, $name, $level = null, $messages = true)
    {
        $output = $messages ? $this->stdErr : new NullOutput();

        if ($level === self::LEVEL_ENVIRONMENT || ($selection->hasEnvironment() && $level === null)) {
            $variable = $selection->getEnvironment()->getVariable($name);
            if ($variable !== false) {
                if ($level === null && $selection->getProject()->getVariable($name)) {
                    $output->writeln('Variable found at both project and environment levels: <error>' . $name . '</error>');
                    $output->writeln("To select a variable, use the --level option ('" . self::LEVEL_PROJECT . "' or '" . self::LEVEL_ENVIRONMENT . "').");

                    return false;
                }

                return $variable;
            }
        }
        if ($level !== self::LEVEL_ENVIRONMENT) {
            $variable = $selection->getProject()->getVariable($name);
            if ($variable !== false) {
                return $variable;
            }
        }
        $output->writeln('Variable not found: <error>' . $name . '</error>');

        return false;
    }

    /**
     * Display a variable to stdout.
     *
     * @param \Platformsh\Client\Model\ApiResourceBase $variable
     */
    public function displayVariable(ApiResourceBase $variable)
    {
        $properties = $variable->getProperties();
        $properties['level'] = $this->getVariableLevel($variable);

        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            if ($key === 'value') {
                $value = wordwrap($value, 80, "\n", true);
            }
            $values[] = $this->formatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);
    }

    /**
     * @param ApiResourceBase $variable
     *
     * @return string
     */
    public function getVariableLevel(ApiResourceBase $variable)
    {
        if ($variable instanceof EnvironmentLevelVariable) {
            return self::LEVEL_ENVIRONMENT;
        } elseif ($variable instanceof ProjectLevelVariable) {
            return self::LEVEL_PROJECT;
        }
        throw new \RuntimeException('Variable level not found');
    }

    /**
     * @return Field[]
     */
    public function getFields()
    {
        $fields = [];

        $fields['level'] = new OptionsField('Level', [
            'description' => 'The level at which to set the variable',
            'shortcut' => 'l',
            'options' => [
                self::LEVEL_PROJECT => 'Project-wide',
                self::LEVEL_ENVIRONMENT => 'Environment-specific',
            ],
            'normalizer' => function ($value) {
                foreach ([self::LEVEL_PROJECT, self::LEVEL_ENVIRONMENT] as $validLevel) {
                    if (stripos($validLevel, $value) === 0) {
                        return $validLevel;
                    }
                }

                return $value;
            },
        ]);
        $fields['environment'] = new OptionsField('Environment', [
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'optionName' => false,
            'questionLine' => 'On what environment should the variable be set?',
            'asChoice' => false,
            'includeAsOption' => false,
        ]);
        $fields['name'] = new Field('Name', [
            'description' => 'The variable name',
            'validators' => [
                function ($value) {
                    return strlen($value) > 256
                        ? 'The variable name exceeds the maximum length, 256 characters.'
                        : true;
                },
                function ($value) {
                    return strpos($value, ' ') !== false
                        ? 'The variable name must not contain a space.'
                        : true;
                },
            ],
        ]);
        $fields['value'] = new Field('Value', [
            'description' => "The variable's value",
        ]);
        $fields['is_json'] = new BooleanField('JSON', [
            'description' => 'Whether the variable is JSON-formatted',
            'questionLine' => 'Is the value JSON-formatted',
            'default' => false,
        ]);
        $fields['is_sensitive'] = new BooleanField('Sensitive', [
            'description' => 'Whether the variable is sensitive',
            'questionLine' => 'Is the value sensitive?',
            'default' => false,
        ]);
        $fields['prefix'] = new OptionsField('Prefix', [
            'description' => "The variable name's prefix",
            'conditions' => [
                'name' => function ($name) {
                    return strpos($name, ':') === false;
                }
            ],
            'options' => $this->getPrefixOptions('NAME'),
            'optionsCallback' => function (array $previousValues) {
                return $this->getPrefixOptions(isset($previousValues['name']) ? $previousValues['name'] : 'NAME');
            },
            'allowOther' => true,
            'default' => 'none',
        ]);
        $fields['is_enabled'] = new BooleanField('Enabled', [
            'optionName' => 'enabled',
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'description' => 'Whether the variable should be enabled',
            'questionLine' => 'Should the variable be enabled?',
        ]);
        $fields['is_inheritable'] = new BooleanField('Inheritable', [
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'description' => 'Whether the variable is inheritable by child environments',
            'questionLine' => 'Is the variable inheritable (by child environments)?',
        ]);
        $fields['visible_build'] = new BooleanField('Visible at build time', [
            'optionName' => 'visible-build',
            'conditions' => [
                'level' => self::LEVEL_PROJECT,
            ],
            'description' => 'Whether the variable should be visible at build time',
            'questionLine' => 'Should the variable be available at build time?',
        ]);
        $fields['visible_runtime'] = new BooleanField('Visible at runtime', [
            'optionName' => 'visible-runtime',
            'conditions' => [
                'level' => self::LEVEL_PROJECT,
            ],
            'description' => 'Whether the variable should be visible at runtime',
            'questionLine' => 'Should the variable be available at runtime?',
        ]);

        return $fields;
    }

    /**
     * @param string $name
     *
     * @return array
     */
    private function getPrefixOptions($name)
    {
        return [
            'none' => 'No prefix: The variable will be part of <comment>$' . $this->config->get('service.env_prefix') . 'VARIABLES</comment>.',
            'env:' => 'env: The variable will be exposed directly, e.g. as <comment>$' . strtoupper($name) . '</comment>.',
        ];
    }
}
