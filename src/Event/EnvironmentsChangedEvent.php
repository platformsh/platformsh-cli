<?php
declare(strict_types=1);

namespace Platformsh\Cli\Event;

use Platformsh\Client\Model\Project;
use Symfony\Component\EventDispatcher\Event;

class EnvironmentsChangedEvent extends Event
{
    private $project;
    private $environments;

    /**
     * @param \Platformsh\Client\Model\Project        $project
     * @param \Platformsh\Client\Model\Environment[]  $environments
     */
    public function __construct(Project $project, array $environments)
    {
        $this->project = $project;
        $this->environments = $environments;
    }

    public function getProject()
    {
        return $this->project;
    }

    public function getEnvironments()
    {
        return $this->environments;
    }
}
