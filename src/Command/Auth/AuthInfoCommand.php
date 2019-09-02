<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuthInfoCommand extends CommandBase
{
    protected static $defaultName = 'auth:info';

    private $api;
    private $formatter;
    private $table;

    public function __construct(Api $api, PropertyFormatter $formatter, Table $table)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Display your account information')
            ->addArgument('property', InputArgument::OPTIONAL, 'The account property to view')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The account property to view (alternate syntax)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $info = $this->api->getMyAccount((bool) $input->getOption('refresh'));
        $propertyWhitelist = ['id', 'uuid', 'display_name', 'username', 'mail', 'has_key'];
        $info = array_intersect_key($info, array_flip($propertyWhitelist));

        $property = $input->getArgument('property');
        if ($input->getOption('property')) {
            if ($property) {
                throw new InvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        'property',
                        'property'
                    )
                );
            }
            $property = $input->getOption('property');
        }

        if ($property) {
            if (!isset($info[$property])) {
                throw new InvalidArgumentException('Property not found: ' . $property);
            }
            $output->writeln($this->formatter->format($info[$property], $property));

            return 0;
        }

        unset($info['uuid']);
        $values = [];
        $header = [];
        foreach ($propertyWhitelist as $property) {
            if (isset($info[$property])) {
                $values[] = $this->formatter->format($info[$property], $property);
                $header[] = $property;
            }
        }
        $this->table->renderSimple($values, $header);

        return 0;
    }
}
