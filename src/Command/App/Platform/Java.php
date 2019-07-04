<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class Java extends Other
{
    public function type() {
        return 'java';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'java'],
            'optionName' => 'runtime_version',
            'options' => ['8', '11'],
            'default' => '11',
        ]);

        return $fields;
    }
}
