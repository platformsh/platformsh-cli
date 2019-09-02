<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Application;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfBuildCommand extends CommandBase
{
    protected static $defaultName = 'self:build';

    private $application;
    private $config;
    private $filesystem;
    private $questionHelper;
    private $shell;

    public function __construct(
        Application $application,
        Config $config,
        Filesystem $filesystem,
        QuestionHelper $questionHelper,
        Shell $shell
    ) {
        $this->application = $application;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->questionHelper = $questionHelper;
        $this->shell = $shell;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Build a new package of the CLI')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to a private key')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The output filename', $this->config->get('application.executable') . '.phar')
            ->addOption('replace-version', null, InputOption::VALUE_OPTIONAL, 'Replace the version number in config.yaml')
            ->addOption('no-composer-rebuild', null, InputOption::VALUE_NONE, ' Skip rebuilding Composer dependencies');
        $this->setHidden(true);
    }

    public function isEnabled()
    {
        // You can't build a Phar from another Phar.
        return !extension_loaded('Phar') || !\Phar::running(false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists(CLI_ROOT . '/vendor')) {
            $this->stdErr->writeln('Directory not found: <error>' . CLI_ROOT . '/vendor</error>');
            $this->stdErr->writeln('Cannot build from a global install');
            return 1;
        }

        $outputFilename = $input->getOption('output');
        if ($outputFilename && !is_writable(dirname($outputFilename))) {
            $this->stdErr->writeln("Not writable: <error>$outputFilename</error>");
            return 1;
        }

        $keyFilename = $input->getOption('key');
        if ($keyFilename && !file_exists($keyFilename)) {
            $this->stdErr->writeln("File not found: <error>$keyFilename</error>");
            return 1;
        }

        $boxConfig = [];

        $version = $this->config->getVersion();

        if ($input->getOption('replace-version')) {
            $version = $input->getOption('replace-version');
        } else {
            $tag = $this->shell->execute(['git', 'describe', '--tags'], CLI_ROOT, false);
            if ($tag !== false) {
                $version = $tag;
            }
            $version = $this->questionHelper->askInput('Version', $version);
        }
        $boxConfig['replacements']['version-placeholder'] = $version;

        if ($outputFilename) {
            $boxConfig['output'] = $this->filesystem->makePathAbsolute($outputFilename);
            $phar = $boxConfig['output'];
        } else {
            // Default output: cli-VERSION.phar in the current directory.
            $boxConfig['output'] = getcwd() . '/cli-' . $version . '.phar';
            $phar = $boxConfig['output'];
        }
        if ($keyFilename) {
            $boxConfig['key'] = realpath($keyFilename);
        }

        if (file_exists($phar)
            && !$this->questionHelper->confirm("File exists: <comment>$phar</comment>. Overwrite?")) {
            return 1;
        }

        if (!$input->getOption('no-composer-rebuild')) {
            $this->stdErr->writeln('Ensuring correct composer dependencies.');
            $this->stdErr->writeln('If this fails, you may need to run "composer install" manually.');

            // Wipe the vendor directory to be extra sure.
            $this->shell->execute(['rm', '-rf', 'vendor'], CLI_ROOT, false);

            // We cannot use --no-dev, as that would exclude the
            // composer-bin-plugin tool.
            $this->shell->execute([
                'composer',
                'install',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);

            // Install composer-bin-plugin dependencies.
            $this->shell->execute([
                'composer',
                'bin',
                'all',
                'install',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);
        }

        $this->stdErr->writeln('Warming application caches');
        $this->application->warmCaches();

        $boxArgs = [CLI_ROOT . '/vendor/bin/box', 'compile', '--no-interaction'];
        if ($output->isVeryVerbose()) {
            $boxArgs[] = '-vvv';
        } elseif ($output->isVerbose()) {
            $boxArgs[] = '-vv';
        } else {
            $boxArgs[] = '-v';
        }

        // Create a temporary box.json file for this build.
        if (!empty($boxConfig)) {
            $originalConfig = json_decode(file_get_contents(CLI_ROOT . '/box.json'), true);
            $boxConfig = array_merge($originalConfig, $boxConfig);
            $boxConfig['base-path'] = CLI_ROOT;
            $filename = tempnam(sys_get_temp_dir(), 'cli-box-');
            file_put_contents($filename, json_encode($boxConfig));
            $boxArgs[] = '--config=' . $filename;
        }

        $this->stdErr->writeln('Building Phar package using Box');
        $result = $this->shell->execute($boxArgs, CLI_ROOT, false, true);
        if ($result === false) {
            return 1;
        }

        // Clean up the temporary file.
        if (!empty($tmpJson)) {
            unlink($tmpJson);
        }

        if (!file_exists($phar)) {
            $this->stdErr->writeln(sprintf('Build failed: file not found: <error>%s</error>', $phar));
            return 1;
        }

        $sha1 = sha1_file($phar);
        $sha256 = hash_file('sha256', $phar);
        $size = filesize($phar);

        $this->stdErr->writeln('The package was built successfully');
        $output->writeln($phar);
        $this->stdErr->writeln([
            sprintf('Size: %s', FormatterHelper::formatMemory($size)),
            sprintf('SHA-1: %s', $sha1),
            sprintf('SHA-256: %s', $sha256),
            sprintf('Version: %s', $version),
        ]);

        return 0;
    }
}
