<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;

class Drupal8Command extends PhpCommand implements ConfigGenerateInterface
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'drupal8';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Drupal 8';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields()
    {
        $commonFields = parent::getFields();

        $fields['runtime_version'] = $commonFields['runtime_version'];
        $fields['webroot'] = $commonFields['webroot'];
        $fields['build_flavor'] = $commonFields['build_flavor'];

        $fields['install_profile'] = new Field('Install profile', [
            'optionName' => 'install-profile',
            'default' => 'standard',
        ]);

        $fields['db_disk'] = new Field('Database disk size (MB)', [
            'optionName' => 'db-disk',
            'default' => 2048,
            'validator' => function ($value) {
                return is_numeric($value) && $value >= 512 && $value < 512000;
            },
            'normalizer' => 'intval',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['services']['mysqldb'] = [
            'type' => 'mysql:10.0',
            'disk' => $this->parameters['db_disk'],
        ];
        $this->parameters['relationships']['database'] = 'mysqldb:mysql';
        $this->parameters['crons']['drupal'] = [
            'spec' => '*/20 * * * *',
            'cmd' => 'cd ' . $this->appRoot . '; drush core-cron'
        ];
        $this->parameters['runtime'] = 'php';
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateTypes()
    {
        $types = parent::getTemplateTypes();
        $webRoot = isset($this->parameters['webroot']) ? $this->parameters['webroot'] : 'web';
        $types['settings.php'] = $webRoot . '/sites/default/settings.php';
        $types['settings.platformsh.php'] = $webRoot . '/sites/default/settings.platformsh.php';

        return $types;
    }
}
