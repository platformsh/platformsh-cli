<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyAddCommand extends CommandBase
{
    const DEFAULT_BASENAME = 'id_rsa';

    protected static $defaultName = 'ssh-key:add';

    private $api;
    private $config;
    private $filesystem;
    private $questionHelper;
    private $shell;

    public function __construct(
        Api $api,
        Config $config,
        Filesystem $filesystem,
        QuestionHelper $questionHelper,
        Shell $shell
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->questionHelper = $questionHelper;
        $this->shell = $shell;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Add a new SSH key')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to an existing SSH public key')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name to identify the key');
        $this->addExample('Add an existing public key', '~/.ssh/id_rsa.pub');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $publicKeyPath = $input->getArgument('path');
        if (empty($publicKeyPath)) {
            $defaultKeyPath = $this->filesystem->getHomeDirectory() . '/.ssh/' . self::DEFAULT_BASENAME;
            $defaultPublicKeyPath = $defaultKeyPath . '.pub';

            // Look for an existing local key.
            if (file_exists($defaultPublicKeyPath)
                && $this->questionHelper->confirm(
                    'Use existing local key <info>' . basename($defaultPublicKeyPath) . '</info>?'
                )) {
                $publicKeyPath = $defaultPublicKeyPath;
            } elseif ($this->shell->commandExists('ssh-keygen')
                && $this->questionHelper->confirm('Generate a new key?')) {
                // Offer to generate a key.
                $newKeyPath = $this->getNewKeyPath();
                $args = ['ssh-keygen', '-t', 'rsa', '-f', $newKeyPath, '-N', ''];
                $this->shell->execute($args, null, true);
                $publicKeyPath = $newKeyPath . '.pub';
                $this->stdErr->writeln("Generated a new key: $publicKeyPath");

                // An SSH agent is required if the key's filename is unusual.
                if (!in_array(basename($newKeyPath), ['id_rsa', 'id_dsa'])) {
                    $this->stdErr->writeln('Add this key to an SSH agent with:');
                    $this->stdErr->writeln('    eval $(ssh-agent)');
                    $this->stdErr->writeln('    ssh-add ' . escapeshellarg($newKeyPath));
                }
            } else {
                $this->stdErr->writeln("<error>You must specify the path to a public SSH key</error>");
                return 1;
            }
        }

        if (!file_exists($publicKeyPath)) {
            $this->stdErr->writeln("File not found: <error>$publicKeyPath<error>");
            return 1;
        }

        // Use ssh-keygen to help validate the key.
        if ($this->shell->commandExists('ssh-keygen')) {
            // Newer versions of ssh-keygen require the -E argument to get an
            // MD5 fingerprint. Older versions of ssh-keygen return an MD5
            // fingerprint anyway.
            $oldArgs = ['ssh-keygen', '-l', '-f', $publicKeyPath];
            $newArgs = array_merge($oldArgs, ['-E', 'md5']);
            $result = $this->shell->execute($newArgs, null, false);
            if ($result === false) {
                $result = $this->shell->execute($oldArgs, null, false);
            }

            // If both commands failed, the key is not valid.
            if ($result === false) {
                $this->stdErr->writeln("The file does not contain a valid public key: <error>$publicKeyPath</error>");
                return 1;
            }

            // Extract the fingerprint from the command output.
            if (preg_match('/^\s*[0-9]+ +(MD5:)?([0-9a-z:]+)( |$)/i', $result, $matches)) {
                $fingerprint = str_replace(':', '', $matches[2]);
            } else {
                $this->debug("Unexpected output from ssh-keygen: $result");
            }

            // Check whether the public key already exists in the user's account.
            if (isset($fingerprint) && $this->keyExistsByFingerprint($fingerprint)) {
                $this->stdErr->writeln(sprintf(
                    'An SSH key already exists in your %s account with the same fingerprint: %s',
                    $this->config->get('service.name'),
                    $fingerprint
                ));
                $this->stdErr->writeln(sprintf(
                    'List your SSH keys with: <info>%s ssh-keys</info>',
                    $this->config->get('application.executable')
                ));

                return 0;
            }
        }

        // Get the public key content.
        $publicKey = file_get_contents($publicKeyPath);
        if ($publicKey === false) {
            $this->stdErr->writeln("Failed to read public key file: <error>$publicKeyPath</error>");
            return 1;
        }

        // Ask for a key name, if it's not specified by the --name option. It
        // will default to the machine's hostname.
        $name = $input->getOption('name');
        if (!$name) {
            $defaultName = gethostname() ?: null;
            $name = $this->questionHelper->askInput('Enter a name for the key', $defaultName);
        }

        // Add the new key.
        $this->api->getClient()->addSshKey($publicKey, $name);

        $this->stdErr->writeln(sprintf(
            'The SSH key <info>%s</info> has been successfully added to your %s account.',
            basename($publicKeyPath),
            $this->config->get('service.name')
        ));

        return 0;
    }

    /**
     * Check whether the SSH key already exists in the user's account.
     *
     * @param string $fingerprint The public key fingerprint (as an MD5 hash).
     *
     * @return bool
     */
    protected function keyExistsByFingerprint($fingerprint)
    {
        foreach ($this->api->getClient()->getSshKeys() as $existingKey) {
            if ($existingKey->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the path for a new SSH key.
     *
     * If the file already exists, this will recurse to find a new filename. The
     * first will be "id_rsa", the second "id_rsa2", the third "id_rsa3", and so
     * on.
     *
     * @param int $number
     *
     * @return string
     */
    protected function getNewKeyPath($number = 1)
    {
        $basename = self::DEFAULT_BASENAME;
        if ($number > 1) {
            $basename = strpos($basename, '.key')
                ? str_replace('.key', '.' . $number . 'key', $basename)
                : $basename . '.' . $number;
        }
        $filename = $this->filesystem->getHomeDirectory() . '/.ssh/' . $basename;
        if (file_exists($filename)) {
            return $this->getNewKeyPath(++$number);
        }

        return $filename;
    }
}
