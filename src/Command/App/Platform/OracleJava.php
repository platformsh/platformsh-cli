<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class OracleJava extends Other
{
    public function type() {
        return 'oracle-java';
    }

    public function getFields() {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'oracle-java'],
            'optionName' => 'runtime_version',
            'options' => ['8'],
            'default' => '8',
        ]);

        return $fields;
    }
}
