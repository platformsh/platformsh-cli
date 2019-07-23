<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Local\LocalProject;


class Fleets
{

    const FLEET_DOES_NOT_EXIST = 0;
    const FLEET_ALREADY_EXISTS = 1;
    const FLEET_ADDED = 2;
    const FLEET_REMOVED = 3;

    const PROJECT_DOES_NOT_EXIST = 4;
    const PROJECT_ALREADY_EXISTS  = 5;
    const PROJECT_ADDED = 6;
    const PROJECT_AND_FLEET_ADDED = 7;
    const PROJECT_REMOVED = 8;

    const PROJECT_ACTIVE = TRUE;

    protected $fleetConfig;

    protected $localProject;
    protected $config;
    protected $filesystem;

    /**
     * Fleets constructor.
     */
    public function __construct()
    {
        $this->localProject = new LocalProject();
        $this->config = new Config();
        $this->filesystem = new Filesystem();

        $this->fleetConfig = array();
    }

    /**
     * Get all fleet configurations.
     *
     * @return array
     *  An array of fleet information where the keys are the fleet names
     */
    public function getFleetConfiguration()
    {

        if (empty($this->fleetConfig)) {
            $this->loadFleetConfiguration();
        }

        // If the config was empty (e.g. the YAML file is blank) or not set at all,
        // initialise it.
        if (empty($this->fleetConfig)) {
            $this->fleetConfig = $this->defaultFleets();
        }
        
        return $this->fleetConfig;
    }

    /**
     * Get information about a specific fleet
     *
     * @param string $fleetName
     *  Name of the fleet
     *
     * @return int|array
     *  The fleet configuration, or a constant.
     */
    public function getFleet($fleetName) {
        $this->getFleetConfiguration();

        if (array_key_exists($fleetName, $this->fleetConfig)) {
            return $this->fleetConfig[$fleetName];
        }

        return self::FLEET_DOES_NOT_EXIST;
    }

    /**
     * Get projects for a given fleet
     *
     * @param string $fleetName
     *  Name of the fleet
     *
     * @return array
     *  An array of project IDs
     */
    public function getFleetProjects($fleetName) {
        $fleet = $this->getFleet($fleetName);

        if (is_array($fleet) && array_key_exists('projects', $fleet)) {
            return $fleet['projects'];
        }

        return array();
    }

    /**
     * Add a fleet.
     *
     * @param string $fleetName
     *  Name of the fleet to add
     *
     * @return int
     *  A constant representing the result of the operation
     */
    public function addFleet($fleetName) {

        $this->getFleetConfiguration();

        if (array_key_exists($fleetName, $this->fleetConfig)) {
            return self::FLEET_ALREADY_EXISTS;
        }

        $this->fleetConfig[$fleetName] = $this->defaultFleet();

        $this->saveFleetsConfiguration();

        return self::FLEET_ADDED;
    }

    /**
     * Remove a fleet from this project
     *
     * @param string $fleetName
     *  Name of the fleet to remove
     *
     * @return int
     *  A constant representing the result
     */
    public function removeFleet($fleetName) {

        $this->getFleetConfiguration();

        if (!array_key_exists($fleetName, $this->fleetConfig)) {
            return self::FLEET_DOES_NOT_EXIST;
        }

        unset($this->fleetConfig[$fleetName]);

        $this->saveFleetsConfiguration();

        return self::FLEET_REMOVED;
    }

    /**
     * Add a project to a fleet
     *
     * @param string $fleetName
     *  The name of the fleet to use
     * @param string $projectID
     *  The project ID
     *
     * @return int
     *  A constant representing the result of the operation
     */
    public function addProject($fleetName, $projectID) {

        $fleet = $this->getFleet($fleetName);

        if ($fleet === self::FLEET_DOES_NOT_EXIST) {
            return self::FLEET_DOES_NOT_EXIST;
        }

        if (in_array($projectID, $fleet)) {
            return self::PROJECT_ALREADY_EXISTS;
        }

        $this->fleetConfig[$fleetName]['projects'][] = $projectID;

        $this->saveFleetsConfiguration();

        return self::PROJECT_ADDED;
    }

    /**
     * Remove a project from a fleet
     *
     * @param string $fleetName
     *  The name of the fleet to use
     * @param string $projectID
     *  The project ID
     *
     * @return int
     *  A constant representing the result of the operation
     */
    public function removeProject($fleetName, $projectID) {

        $fleet = $this->getFleet($fleetName);

        if ($fleet === self::FLEET_DOES_NOT_EXIST) {
            return self::FLEET_DOES_NOT_EXIST;
        }

        if (in_array($projectID, $fleet['projects'])) {

            if (($key = array_search($projectID, $this->fleetConfig[$fleetName]['projects'])) !== false) {
                unset($this->fleetConfig[$fleetName]['projects'][$key]);
            }

            $this->saveFleetsConfiguration();

            return self::PROJECT_REMOVED;
        }

        return self::PROJECT_DOES_NOT_EXIST;
    }

    /**
     * Get the name of the configuration file for fleets.
     *
     * @return string
     */
    protected function getConfigFileName()
    {
        return $this->config->get('service.project_config_dir') . '/fleets.yaml';
    }

    /**
     * Save the fleet configuration file.
     */
    protected function saveFleetsConfiguration()
    {
        if ($this->filesystem->fileExists($this->getConfigFileName())) {
            $this->filesystem->remove($this->getConfigFileName());
        }

        $this->filesystem->createYamlFile($this->getConfigFileName(), $this->fleetConfig);
    }

    /**
     * Return default fleets configuration.
     *
     * @return array
     */
    protected function defaultFleets() {
        return [];
    }

    /**
     * Return default fleet configuration.
     *
     * @return array
     */
    protected function defaultFleet() {
        return [
            'projects' => [],
        ];
    }

    /**
     * Load current fleet configuration for this project from the filesystem
     */
    protected function loadFleetConfiguration()
    {
        $configFileName = $this->getConfigFileName();

        if ($this->filesystem->fileExists($configFileName)) {
            $this->fleetConfig = $this->filesystem->readYamlFile($configFileName);
        }

    }
}
