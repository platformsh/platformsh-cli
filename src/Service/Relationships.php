<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Relationships implements InputConfiguringInterface
{

    protected $envVarService;

    /**
     * @param RemoteEnvVars $envVarService
     */
    public function __construct(RemoteEnvVars $envVarService)
    {
        $this->envVarService = $envVarService;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public function configureInput(InputDefinition $definition): void
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The service relationship to use')
        );
    }

    /**
     * Choose a database for the user.
     *
     * @param HostInterface $host
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return array|false
     */
    public function chooseDatabase(HostInterface $host, InputInterface $input, OutputInterface $output)
    {
        return $this->chooseService($host, $input, $output, ['mysql', 'pgsql']);
    }

    /**
     * Choose a service for the user.
     *
     * @param HostInterface   $host
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string[]        $schemes Filter by scheme.
     *
     * @return array|false
     */
    public function chooseService(HostInterface $host, InputInterface $input, OutputInterface $output, $schemes = [])
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $relationships = $this->getRelationships($host);

        // Filter to find services matching the schemes.
        if (!empty($schemes)) {
            $relationships = array_filter($relationships, function (array $relationship) use ($schemes) {
                foreach ($relationship as $key => $service) {
                    if (isset($service['scheme']) && in_array($service['scheme'], $schemes, true)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if (empty($relationships)) {
            if (!empty($schemes)) {
                $stdErr->writeln(sprintf('No relationships found matching scheme(s): <error>%s</error>.', implode(', ', $schemes)));
            } else {
                $stdErr->writeln(sprintf('No relationships found'));
            }
            return false;
        }

        // Collapse relationships and services into a flat list.
        $choices = [];
        foreach ($relationships as $name => $relationship) {
            $serviceCount = count($relationship);
            foreach ($relationship as $key => $info) {
                $identifier = $name . ($serviceCount > 1 ? '.' . $key : '');
                if (isset($info['username']) && (!isset($info['host']) || $info['host'] === '127.0.0.1')) {
                    $choices[$identifier] = sprintf('%s (%s)', $identifier, $info['username']);
                } elseif (isset($info['username'], $info['host'])) {
                    $choices[$identifier] = sprintf('%s (%s@%s)', $identifier, $info['username'], $info['host']);
                } else {
                    $choices[$identifier] = $identifier;
                }
            }
        }

        // Use the --relationship option, if specified.
        $identifier = false;
        if ($input->hasOption('relationship')
            && ($relationshipName = $input->getOption('relationship'))) {
            // Normalise the relationship name to remove a trailing ".0".
            if (substr($relationshipName, -2) === '.0'
                && isset($relationships[$relationshipName]) && count($relationships[$relationshipName]) ===1) {
                $relationshipName = substr($relationshipName, 0, strlen($relationshipName) - 2);
            }
            if (!isset($choices[$relationshipName])) {
                $stdErr->writeln('Relationship not found: <error>' . $relationshipName . '</error>');
                return false;
            }
            $identifier = $relationshipName;
        }

        if (!$identifier && count($choices) === 1) {
            $identifier = key($choices);
        }

        if (!$identifier && !$input->isInteractive()) {
            $stdErr->writeln('More than one relationship found.');
            if ($input->hasOption('relationship')) {
                $stdErr->writeln('Use the <error>--relationship</error> (-r) option to specify a relationship. Options:');
                foreach (array_keys($choices) as $identifier) {
                    $stdErr->writeln('    ' . $identifier);
                }
            }
            return false;
        }

        if (!$identifier) {
            $questionHelper = new QuestionHelper($input, $output);
            $identifier = $questionHelper->choose($choices, 'Enter a number to choose a relationship:');
        }

        if (strpos($identifier, '.') !== false) {
            list($name, $key) = explode('.', $identifier, 2);
        } else {
            $name = $identifier;
            $key = 0;
        }
        $relationship = $relationships[$name][$key];

        // Ensure the service name is included in the relationship info.
        // This is for backwards compatibility with projects that do not have
        // this information.
        if (!isset($relationship['service'])) {
            $appConfig = $this->envVarService->getArrayEnvVar('APPLICATION', $host);
            if (!empty($appConfig['relationships'][$name]) && is_string($appConfig['relationships'][$name])) {
                list($serviceName, ) = explode(':', $appConfig['relationships'][$name], 2);
                $relationship['service'] = $serviceName;
            }
        }

        // Add metadata about the service.
        $relationship['_relationship_name'] = $name;
        $relationship['_relationship_key'] = $key;

        return $relationship;
    }

    /**
     * Get the relationships deployed on the application.
     *
     * @param HostInterface $host
     * @param bool          $refresh
     *
     * @return array
     */
    public function getRelationships(HostInterface $host, $refresh = false)
    {
        return $this->envVarService->getArrayEnvVar('RELATIONSHIPS', $host, $refresh);
    }

    /**
     * Returns command-line arguments to connect to a database.
     *
     * @param string      $command        The command that will need arguments
     *                                    (one of 'psql', 'pg_dump', 'mysql',
     *                                    or 'mysqldump').
     * @param array       $database       The database definition from the
     *                                    relationship.
     * @param string|null $schema         The name of a database schema, or
     *                                    null to use the default schema, or
     *                                    an empty string to not select a
     *                                    schema.
     *
     * @return string
     *   The command line arguments (excluding the $command).
     */
    public function getDbCommandArgs($command, array $database, $schema = null)
    {
        if ($schema === null) {
            $schema = $database['path'];
        }

        switch ($command) {
            case 'psql':
            case 'pg_dump':
                $url = sprintf(
                    'postgresql://%s:%s@%s:%d',
                    $database['username'],
                    $database['password'],
                    $database['host'],
                    $database['port']
                );
                if ($schema !== '') {
                    $url .= '/' . rawurlencode($schema);
                }

                return OsUtil::escapePosixShellArg($url);

            case 'mysql':
            case 'mysqldump':
                $args = sprintf(
                    '--user=%s --password=%s --host=%s --port=%d',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port']
                );
                if ($schema !== '') {
                    $args .= ' ' . OsUtil::escapePosixShellArg($schema);
                }

                return $args;

            case 'mongo':
            case 'mongodump':
            case 'mongoexport':
            case 'mongorestore':
                $args = sprintf(
                    '--username %s --password %s --host %s --port %d',
                    OsUtil::escapePosixShellArg($database['username']),
                    OsUtil::escapePosixShellArg($database['password']),
                    OsUtil::escapePosixShellArg($database['host']),
                    $database['port']
                );
                if ($schema !== '') {
                    $args .= ' --authenticationDatabase ' . OsUtil::escapePosixShellArg($schema);
                    if ($command === 'mongo') {
                        $args .= ' ' . OsUtil::escapePosixShellArg($schema);
                    } else {
                        $args .= ' --db ' . OsUtil::escapePosixShellArg($schema);
                    }
                }

                return $args;
        }

        throw new \InvalidArgumentException('Unrecognised command: ' . $command);
    }

    /**
     * @param string $sshUrl
     */
    public function clearCaches($sshUrl)
    {
        $this->envVarService->clearCaches($sshUrl);
    }

    /**
     * @return bool
     */
    public function hasLocalEnvVar()
    {
        return $this->envVarService->getEnvVar('RELATIONSHIPS', new LocalHost()) !== '';
    }
}
