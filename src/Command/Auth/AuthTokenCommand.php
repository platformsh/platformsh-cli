<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AuthTokenCommand extends CommandBase
{
    protected static $defaultName = 'auth:token';

    private $config;
    private $api;

    public function __construct(Config $config, Api $api)
    {
        $this->config = $config;
        $this->api = $api;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription(sprintf(
                'Obtain an OAuth 2 access token for requests to %s APIs',
                $this->config->get('service.name')
            ));
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr->writeln(
            '<comment>Keep access tokens secret. Using this command is not recommended.</comment>'
        );

        $output->writeln($this->api->getAccessToken());

        return 0;
    }
}
