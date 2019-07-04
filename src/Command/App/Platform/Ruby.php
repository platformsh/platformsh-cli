<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class Ruby extends Other
{
    public function type() {
        return 'ruby';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'ruby'],
            'optionName' => 'runtime_version',
            'options' => ['2.3', '2.4', '2.5'],
            'default' => '2.5',
        ]);

        return $fields;
    }
}
