<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class Golang extends Other
{
    public function type() {
        return 'golang';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'golang'],
            'optionName' => 'runtime_version',
            'options' => ['1.11'],
            'default' => '1.11',
        ]);

        return $fields;
    }
}
