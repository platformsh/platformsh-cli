<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class NodeJs extends Other
{
    public function type() {
        return 'nodejs';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'nodejs'],
            'optionName' => 'runtime_version',
            'options' => ['6.11', '8.9', '10'],
            'default' => '10',
        ]);

        return $fields;
    }
}
