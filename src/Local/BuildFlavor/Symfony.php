<?php
declare(strict_types=1);

namespace Platformsh\Cli\Local\BuildFlavor;

class Symfony extends Composer
{

    public function getKeys()
    {
        return ['symfony'];
    }

    public function install()
    {
        parent::install();
        $this->copyGitIgnore('symfony/gitignore-standard');
    }
}
