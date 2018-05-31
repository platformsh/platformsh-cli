<?php

namespace Platformsh\Cli\Command\Config;

class Drupal7Command extends PhpCommand implements ConfigGenerateInterface
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'drupal7';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Drupal 7';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields()
    {
        $commonFields = parent::getFields();

        $fields['runtime_version'] = $commonFields['runtime_version'];
        $fields['webroot'] = $commonFields['webroot']
            ->set('default', 'public');

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['services']['mysqldb'] = [
            'type' => 'mysql:10.0',
            'disk' => 2048,
        ];
        $this->parameters['relationships']['database'] = 'mysqldb:mysql';
        $this->parameters['crons']['drupal'] = [
            'spec' => '*/20 * * * *',
            'cmd' => 'cd ' . $this->appRoot . '; drush core-cron'
        ];
        $this->parameters['runtime'] = 'php';
    }
}
