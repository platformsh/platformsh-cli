<?php


namespace Platformsh\Cli\Command\Config;

/**
 * Interface ConfigGenerateInterface
 *
 * Defines a configuration generator.
 *
 * @package Platformsh\Cli\Command\Config
 */
interface ConfigGenerateInterface
{

    /**
     * The name of the command.
     *
     * e.g. the 'php' in config:generate:php
     *
     * @return string
     *   A machine-readable name
     */
    public function getKey();

    /**
     * The human readable label for the command.
     *
     * e.g. 'Drupal 7'
     *
     * @return string
     *   A human readable name
     */
    public function getLabel();

    /**
     * An array of field information for the interactive generator.
     *
     * See Command/Config/PhpCommand::getFields() for a comprehensive example.
     *
     * @return array
     *   The $fields array
     */
    public function getFields();

    /**
     * Gives the implementation an option to add or change YAML parameters
     * before writing.
     *
     * This works directly on $this->parameters and does not return anything.
     */
    public function alterParameters();

    /**
     * Returns a list of valid template types for this generator.
     *
     * The base list in \Platformsh\Cli\Command\Config\ConfigGenerateCommandBase::getTemplateTypes
     * is fine in most cases.
     */
    public function getTemplateTypes();
}
