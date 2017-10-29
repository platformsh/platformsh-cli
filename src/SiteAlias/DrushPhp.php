<?php

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Client\Model\Project;

class DrushPhp extends DrushAlias
{
    /**
     * {@inheritdoc}
     */
    protected function getFilename($groupName, $drushDir)
    {
        return $drushDir . '/' . $groupName . '.aliases.drushrc.php';
    }

    /**
     * {@inheritdoc}
     */
    protected function formatAliases(array $aliases)
    {
        $formatted = [];
        foreach ($aliases as $aliasName => $newAlias) {
            $formatted[] = sprintf(
                "\$aliases['%s'] = %s;\n",
                str_replace("'", "\\'", $aliasName),
                var_export($newAlias, true)
            );
        }

        return implode("\n", $formatted);
    }

    /**
     * {@inheritdoc}
     */
    protected function getHeader(Project $project)
    {
        return <<<EOT
<?php
/**
 * Drush aliases for the {$this->config->get('service.name')} project "{$project->title}".
 *
 * This file is auto-generated by the {$this->config->get('application.name')}.
 *
 * WARNING
 * This file may be regenerated at any time.
 * - User-defined aliases will be preserved.
 * - Aliases for active environments (including any custom additions) will be preserved.
 * - Aliases for deleted or inactive environments will be deleted.
 * - All other information will be deleted.
 */
EOT;
    }

    /**
     * Generate new aliases.
     *
     * @param array $apps
     * @param array $environments
     *
     * @return array
     */
    protected function generateNewAliases(array $apps, array $environments)
    {
        $aliases = [];

        foreach ($apps as $app) {
            $appId = $app->getId();

            // Generate an alias for the local environment.
            $localAliasName = self::LOCAL_ALIAS_NAME;
            if (count($apps) > 1) {
                $localAliasName .= '--' . $appId;
            }
            $aliases[$localAliasName] = $this->generateLocalAlias($app);

            // Generate aliases for the remote environments.
            foreach ($environments as $environment) {
                $alias = $this->generateRemoteAlias($environment, $app, !$app->isSingle());
                if (!$alias) {
                    continue;
                }

                $aliasName = $environment->id;
                if (count($apps) > 1) {
                    $aliasName .= '--' . $appId;
                }

                $aliases[$aliasName] = $alias;
            }
        }

        return $aliases;
    }
}
