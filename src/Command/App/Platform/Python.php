<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class Python extends Other
{
    public function type() {
        return 'python';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'python'],
            'optionName' => 'runtime_version',
            'options' => ['2.7', '3.5', '3.6', '3.7'],
            'default' => '3.7',
        ]);

        return $fields;
    }
}
