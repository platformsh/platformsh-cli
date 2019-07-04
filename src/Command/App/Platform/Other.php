<?php

namespace Platformsh\Cli\Command\App\Platform;

use Platformsh\ConsoleForm\Field\OptionsField;

abstract class Other implements PlatformInterface
{
    public function appYamlTemplate()
    {
        // @todo Replace this with a web service call to get a template off of GitHub.

        $template = <<<END
# This file defines one application within your project. Each application is rooted
# at the directory where this file exists, and will produce a single application
# container to run your code.  The basic file below contains only the required
# options. To get to a running application you'll need at least to supply
# a real "start" command.
# See https://docs.platform.sh/configuration/app-containers.html#configure-your-application

name: {name}
type: {type}:{runtime_version}
web:
## The commands section lists programs to start when the container is deployed,
## typically starting your application.
#  commands:
#    start: sleep infinity
  locations:
    "/public":
      passthru: false
      root: "public"

## You can add services in .platform/services.yaml, to use them in your application
## you will need to create a relationship for example:
#
# relationships:
#     database: "mysql:mysql"
#     redis: "redis:redis"

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
