<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class Php implements PlatformInterface {

    public function name()
    {
        return 'php';
    }

    public function getFields()
    {
        $fields['php_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'php_version',
            'options' => ['5.6', '7.0'],
            'default' => '7.0',
        ]);

        $fields['flavor'] = new OptionsField('Flavor', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'flavor',
            'options' => ['composer', 'drupal'],
            'default' => 'composer',
        ]);

        $fields['webroot'] = new Field('Web directory', [
            'conditions' => ['type' => 'php'],
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

        $fields['indexFile'] = new Field('Front controller', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'indexFile',
            'default' => '/index.php',
            'validator' => function ($value) {
                if (!preg_match('/^\/.*/', $value)) {
                    return 'The front controller must be an absolute with path to a PHP file, starting with /.';
                }
                if (preg_match('/^\w*\.php/', $value)) {
                    return 'The front controller must end in .php and contain no spaces.';
                }
                return true;
            },
        ]);

        return $fields;
    }

    public function appYamlTemplate()
    {
        // @todo Replace this with a web service call to get a template off of GitHub.

        $template = <<<END
# This file defines one application within your project. Each application is rooted
# at the directory where this file exists, and will produce a single application
# container to run your code.  The basic file below shows the key options available,
# but wil likely need additional customization for your application.
# See URL for more information.

name: {name}
type: php:{php_version}

build:
    flavor: {flavor}

relationships:
    database: "mysql:mysql"
    solr: "solr:solr"
    redis: "redis:redis"

web:
    locations:
        "/":
            root: "{webroot}"
            passthru: "{indexFile}"

# The size in megabytes of persistent disk space to reserve as part of this application.
disk: 2048

# Each mount is a pairint of the local path on the application container to
# the persistent mount where it lives. At this time, only 'shared:files' is
# a supported mount.
mounts:
    "/public/sites/default/files": "shared:files/files"
    "/tmp": "shared:files/tmp"
    "/private": "shared:files/private"

END;
        return $template;

    }
}
