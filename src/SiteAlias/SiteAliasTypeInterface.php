<?php
declare(strict_types=1);

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Client\Model\Project;

interface SiteAliasTypeInterface
{
    /**
     * Create an alias file.
     *
     * @param \Platformsh\Client\Model\Project         $project
     * @param string                                   $aliasGroup
     * @param \Platformsh\Cli\Local\LocalApplication[] $apps
     * @param \Platformsh\Client\Model\Environment[]   $environments
     * @param string|null                              $previousGroup
     *
     * @throws \RuntimeException
     *
     * @return bool Whether any aliases have been created.
     */
    public function createAliases(Project $project, $aliasGroup, array $apps, array $environments, $previousGroup = null);

    /**
     * Delete old alias file(s).
     *
     * @param string $group
     */
    public function deleteAliases($group);
}
