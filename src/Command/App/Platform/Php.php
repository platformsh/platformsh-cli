<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class Php implements PlatformInterface {

    public function type()
    {
        return 'php';
    }

    public function getFields()
    {
        $fields['runtime_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'runtime_version',
            'options' => ['7.1', '7.2', '7.3'],
            'default' => '7.3',
        ]);

        $fields['flavor'] = new OptionsField('Flavor', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'flavor',
            'options' => ['none', 'composer', 'drupal'],
            'default' => 'composer',
        ]);

        $fields['webroot'] = new Field('Web directory', [
            'conditions' => ['type' => 'php'],
            'optionName' => 'webroot',
            'default' => 'public',
            'validator' => function ($value) {
                if (preg_match('/^\/.*/', $value)) {
                    return 'The web root must not begin with a /. It is a directory relative to the application root from which you want to serve scripts and files.';
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
type: {type}:{runtime_version}

build:
    flavor: {flavor}

## You can add services in .platform/services.yaml, to use them in your application
## you will need to create a relationship for example:
#
# relationships:
#     database: "mysql:mysql"
#     redis: "redis:redis"

web:
    locations:
        "/":
            root: "{webroot}"
            passthru: "{indexFile}"

# The size in megabytes of persistent disk space to reserve as part of this application.
disk: 1024

## Each mount is a pairint of the local path on the application container to
## the persistent mount where it lives. At this time, only 'shared:files' is
## a supported mount.
#mounts:
#    "/tmp": "shared:files/tmp"


END;
        return $template;

    }
}
