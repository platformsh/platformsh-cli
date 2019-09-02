<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountSizeCommand extends CommandBase
{
    protected static $defaultName = 'mount:size';

    private $config;
    private $mountService;
    private $selector;
    private $shell;
    private $ssh;
    private $table;

    public function __construct(
        Config $config,
        Mount $mountService,
        Selector $selector,
        Shell $shell,
        Ssh $ssh,
        Table $table
    ) {
        $this->config = $config;
        $this->mountService = $mountService;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Check the disk usage of mounts')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition, true);
        $this->table->configureInput($definition);
        $this->ssh->configureInput($definition);

        $appConfigFile = $this->config->get('service.app_config_file');

        $this->setHelp(<<<EOF
Use this command to check the disk size and usage for an application's mounts.

Mounts are directories mounted into the application from a persistent, writable
filesystem. They are configured in the <info>mounts</info> key in the <info>$appConfigFile</info> file.

The filesystem's total size is determined by the <info>disk</info> key in the same file.
EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->selector->getSelection($input)->getRemoteContainer();
        $mounts = $container->getMounts();

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('The %s "%s" doesn\'t define any mounts.', $container->getType(), $container->getName()));

            return 1;
        }

        $this->stdErr->writeln(sprintf('Checking disk usage for all mounts of the %s <info>%s</info>...', $container->getType(), $container->getName()));
        $this->stdErr->writeln('');

        // Get a list of the mount paths (and normalize them as relative paths,
        // relative to the application directory).
        $mountPaths = [];
        foreach (array_keys($mounts) as $mountPath) {
            $mountPaths[] = trim(trim($mountPath), '/');
        }

        // Build a list of multiple commands that will be run over the same SSH
        // connection:
        //   1. Get the application directory (by reading the PLATFORM_APP_DIR
        //      environment variable).
        //   2. Run the 'df' command to find filesystem statistics for the
        //      mounts.
        //   3. Run a 'du' command on each of the mounted paths, to find their
        //      individual sizes.
        $appDirVar = $this->config->get('service.env_prefix') . 'APP_DIR';
        $commands = [];
        $commands[] = 'echo "$' . $appDirVar . '"';
        $commands[] = 'echo';
        $commands[] = 'df -P -B1 -a -x squashfs -x tmpfs -x sysfs -x proc -x devpts -x rpc_pipefs';
        $commands[] = 'echo';
        $commands[] = 'cd "$' . $appDirVar . '"';

        foreach ($mountPaths as $mountPath) {
            $commands[] = 'du --block-size=1 -s ' . escapeshellarg($mountPath);
        }
        $command = 'set -e; ' . implode('; ', $commands);

        // Connect to the application via SSH and run the commands.
        $sshArgs = [
            'ssh',
            $container->getSshUrl(),
        ];
        $sshArgs = array_merge($sshArgs, $this->ssh->getSshArgs());
        $result = $this->shell->execute(array_merge($sshArgs, [$command]), null, true);

        // Separate the commands' output.
        list($appDir, $dfOutput, $duOutput) = explode("\n\n", $result, 3);

        // Parse the output.
        $volumeInfo = $this->parseDf($dfOutput, $appDir, $mountPaths);
        $mountSizes = $this->parseDu($duOutput, $mountPaths);

        // Build a table of results: one line per mount, one (multi-line) row
        // per filesystem.
        $header = [
            'mounts' => 'Mount(s)',
            'sizes' => 'Size(s)',
            'max' => 'Disk',
            'used' => 'Used',
            'available' => 'Available',
            'percent_used' => '% Used',
        ];
        $rows = [];
        $showInBytes = $input->getOption('bytes');
        foreach ($volumeInfo as $info) {
            $row = [];
            $row['mounts'] = implode("\n", $info['mounts']);
            $mountUsage = [];
            foreach ($info['mounts'] as $mountPath) {
                $mountUsage[] = $mountSizes[$mountPath];
            }
            if ($showInBytes) {
                $row['sizes'] = implode("\n", $mountUsage);
                $row['max'] = $info['total'];
                $row['used'] = $info['used'];
                $row['available'] = $info['available'];
            } else {
                $row['sizes'] = implode("\n", array_map([Helper::class, 'formatMemory'], $mountUsage));
                $row['max'] = Helper::formatMemory($info['total']);
                $row['used'] = Helper::formatMemory($info['used']);
                $row['available'] = Helper::formatMemory($info['available']);
            }
            $row['percent_used'] = round($info['percent_used'], 1) . '%';
            $rows[] = $row;
        }

        $this->table->render($rows, $header);

        if (!$table->formatIsMachineReadable()) {
            if (count($volumeInfo) === 1 && count($mountPaths) > 1) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('All the mounts share the same disk.');
            }
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To increase the available space, edit the <info>disk</info> key in the <info>%s</info> file.',
                $this->config()->get('service.app_config_file')
            ));
        }

        return 0;
    }

    /**
     * Get a column from a line of df output.
     *
     * Unfortunately there doesn't seem to be a more reliable way to parse df
     * output than by regular expression.
     *
     * @param string $line
     * @param string $columnName
     *
     * @return string
     */
    private function getDfColumn($line, $columnName)
    {
        $columnPatterns = [
            'filesystem' => '#^(.+?)(\s+[0-9])#',
            'total' => '#([0-9]+)\s+[0-9]+\s+[0-9]+\s+[0-9]+%\s+#',
            'used' => '#([0-9]+)\s+[0-9]+\s+[0-9]+%\s+#',
            'available' => '#([0-9]+)\s+[0-9]+%\s+#',
            'path' => '#%\s+(/.+)$#',
        ];
        if (!isset($columnPatterns[$columnName])) {
            throw new \InvalidArgumentException("Invalid df column: $columnName");
        }
        if (!preg_match($columnPatterns[$columnName], $line, $matches)) {
            throw new \RuntimeException("Failed to find column '$columnName' in df line: \n$line");
        }

        return trim($matches[1]);
    }

    /**
     * Parse the output of 'df', building a list of results per FS volume.
     *
     * @param string $dfOutput
     * @param string $appDir
     * @param array  $mountPaths
     *
     * @return array
     */
    private function parseDf($dfOutput, $appDir, array $mountPaths)
    {
        $results = [];
        foreach (explode("\n", $dfOutput) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            try {
                $path = $this->getDfColumn($line, 'path');
            } catch (\RuntimeException $e) {
                $this->debug($e->getMessage());
                continue;
            }
            if (strpos($path, $appDir . '/') !== 0) {
                continue;
            }
            $mountPath = ltrim(substr($path, strlen($appDir)), '/');
            if (!in_array($mountPath, $mountPaths)) {
                continue;
            }
            $filesystem = $this->getDfColumn($line, 'filesystem');
            if (isset($results[$filesystem])) {
                $results[$filesystem]['mounts'][] = $mountPath;
                continue;
            }
            $results[$filesystem] = [
                'total' => $this->getDfColumn($line, 'total'),
                'used' => $this->getDfColumn($line, 'used'),
                'available' => $this->getDfColumn($line, 'available'),
                'mounts' => [$mountPath],
            ];
            $results[$filesystem]['percent_used'] = $results[$filesystem]['used'] / $results[$filesystem]['total'] * 100;
        }

        return $results;
    }

    /**
     * Parse the 'du' output.
     *
     * @param string $duOutput
     * @param array  $mountPaths
     *
     * @return array A list of mount sizes (in bytes) keyed by mount path.
     */
    private function parseDu($duOutput, array $mountPaths)
    {
        $mountSizes = [];
        $duOutputSplit = explode("\n", $duOutput, count($mountPaths));
        foreach ($mountPaths as $i => $mountPath) {
            if (!isset($duOutputSplit[$i])) {
                throw new \RuntimeException("Failed to find row $i of 'du' command output: \n" . $duOutput);
            }
            list($mountSizes[$mountPath],) = explode("\t", $duOutputSplit[$i], 2);
        }

        return $mountSizes;
    }
}
