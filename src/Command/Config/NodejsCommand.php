<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\OptionsField;

class NodejsCommand extends ConfigGenerateCommandBase
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'nodejs';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Generic Node.js';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Node.js version', [
            'optionName' => 'runtime-version',
            'options' => ['6.11', '8.9', '10'],
            'default' => '10',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['runtime'] = 'nodejs';
    }


}
