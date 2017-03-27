<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

class NodeJs implements PlatformInterface
{
    public function name() {
        return 'nodejs';
    }

    public function getFields() {
        $fields['nodejs_version'] = new OptionsField('Version', [
            'conditions' => ['type' => 'nodejs'],
            'optionName' => 'php_version',
            'options' => ['5.6', '7.0'],
            'default' => '7.0',
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
type: nodejs:{nodejs_version}

# Dependencies list tools that are not part of your application but that are needed
# to build or run it.
dependencies:
  nodejs:
    pm2: "^0.15.10"

relationships:
    database: "mysql:mysql"
    solr: "solr:solr"
    redis: "redis:redis"

web:
  # The commands section lists programs to start when the container is deployed,
  # typically starting your node application.
  commands:
    start: "PM2_HOME=/app/run pm2 start index.js --no-daemon"
    #in this setup you will find your application stdout and stderr in /app/run/logs
  locations:
    "/public":
      passthru: false
      root: "public"
      # Whether to allow files not matching a rule or not.
      allow: true
      rules:
        '\.png$':
          allow: true
          expires: -1

# The size in megabytes of persistent disk space to reserve as part of this application.
disk: 512

# Each mount is a pairint of the local path on the application container to
# the persistent mount where it lives. At this time, only 'shared:files' is
# a supported mount.
mounts:
  "/run": "shared:files/run"

END;
        return $template;
    }
}
