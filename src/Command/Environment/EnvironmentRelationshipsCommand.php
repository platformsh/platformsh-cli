<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Psr7\Uri;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends CommandBase
{
    protected static $defaultName = 'environment:relationships';

    private $config;
    private $formatter;
    private $relationships;
    private $selector;
    private $ssh;

    public function __construct(
        Config $config,
        PropertyFormatter $formatter,
        Relationships $relationships,
        Selector $selector,
        Ssh $ssh
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setAliases(['relationships'])
            ->setDescription('Show an environment\'s relationships')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the relationships');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->ssh->configureInput($definition);

        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'master' environment's relationships", 'master');
        $this->addExample("View the 'master' environment's database port", 'master --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, false, $this->relationships->hasLocalEnvVar());
        $relationships = $this->relationships->getRelationships($selection->getHost(), $input->getOption('refresh'));

        foreach ($relationships as $name => $relationship) {
            foreach ($relationship as $index => $instance) {
                if (!isset($instance['url'])) {
                    $relationships[$name][$index]['url'] = $this->buildUrl($instance);
                }
            }
        }

        $this->formatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }

    /**
     * Builds a URL from the parts included in a relationship array.
     *
     * @param array $instance
     *
     * @return string
     */
    private function buildUrl(array $instance)
    {
        // Convert to \GuzzleHttp\Psr7\Uri parts.
        $map = [
            'scheme' => 'scheme',
            'user' => 'username',
            'pass' => 'password',
            'host' => 'host',
            'port' => 'port',
            'path' => 'path',
            'fragment' => 'fragment',
        ];
        $parts = [];
        foreach ($map as $uriPart => $property) {
            if (array_key_exists($property, $instance)) {
                $parts[$uriPart] = $instance[$property];
            }
        }
        $uri = Uri::fromParts($parts);

        if (isset($instance['query'])) {
            $uri = Uri::withQueryValues($uri, $instance['query']);
        }

        return $uri->__toString();
    }
}
