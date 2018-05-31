<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class PhpCommand extends ConfigGenerateCommandBase implements ConfigGenerateInterface
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'php';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Generic PHP';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields()
    {
        $fields = [];

        $fields['runtime_version'] = new OptionsField('PHP version', [
            'optionName' => 'runtime-version',
            'options' => ['5.6', '7.0', '7.1', '7.2'],
            'default' => '7.2',
        ]);

        $fields['build_flavor'] = new OptionsField('Build flavor', [
            'optionName' => 'flavor',
            'options' => ['composer', 'none'],
            'default' => 'composer',
        ]);

        $fields['webroot'] = new Field('Web root directory', [
            'optionName' => 'webroot',
            'default' => 'web',
            'validator' => function ($value) {
                if (preg_match('/^\/.*/', $value)) {
                    return 'The web root must not begin with a /. It is a directory relative to the application root.';
                }
                if (preg_match('/\s+/', $value)) {
                    return 'The web root must not contain spaces.';
                }
                return true;
            },
        ]);

        $fields['front_controller'] = new Field('Front controller', [
            'optionName' => 'front-controller',
            'default' => '/index.php',
            'validator' => function ($value) {
                if (!preg_match('/^\/.*/', $value)) {
                    return 'The front controller must be an absolute URL path to a PHP file, starting with /.';
                }
                if (preg_match('/^\w*\.php/', $value)) {
                    return 'The front controller must end in .php and contain no spaces.';
                }
                return true;
            },
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['runtime'] = 'php';
    }


}
