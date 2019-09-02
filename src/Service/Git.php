<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\DependencyMissingException;

/**
 * Helper class which runs Git CLI commands and interprets the results.
 */
class Git
{

    /** @var string|null */
    private $repositoryDir = null;

    /** @var Shell */
    private $shellHelper;

    /** @var array */
    private $env = [];

    /** @var string|null */
    private $sshCommandFile;

    /**
     * Constructor.
     *
     * @param Shell|null $shellHelper
     */
    public function __construct(Shell $shellHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new Shell();
    }

    /**
     * Get the installed version of the Git CLI.
     *
     * @return string|false
     *   The version number, or false on failure.
     */
    private function getVersion()
    {
        static $version;
        if (!$version) {
            $version = false;
            $string = $this->execute(['--version'], false);
            if ($string && preg_match('/(^| )([0-9]+[^ ]*)/', $string, $matches)) {
                $version = $matches[2];
            }
        }

        return $version;
    }

    /**
     * Ensure that the Git CLI is installed.
     *
     * @throws DependencyMissingException
     */
    public function ensureInstalled(): void
    {
        if (!$this->shellHelper->commandExists('git')) {
            throw new DependencyMissingException('Git must be installed');
        }
    }

    /**
     * Set the repository directory.
     *
     * The default is the current working directory.
     *
     * @param string $dir
     *   The path to a Git repository.
     */
    public function setDefaultRepositoryDir(string $dir): void
    {
        $this->repositoryDir = $dir;
    }

    /**
     * Get the current branch name.
     *
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getCurrentBranch(?string $dir = null, bool $mustRun = false)
    {
        $args = ['symbolic-ref', '--short', 'HEAD'];

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Get a list of branches merged with a specific ref.
     *
     * @param string $ref
     * @param bool   $remote
     * @param string|null $dir
     * @param bool   $mustRun
     *
     * @return string[]
     */
    public function getMergedBranches(string $ref = 'HEAD', bool $remote = false, ?string $dir = null, bool $mustRun = false): array
    {
        $args = ['branch', '--list', '--no-column', '--no-color', '--merged', $ref];
        if ($remote) {
            $args[] = '--remote';
        }
        $mergedBranches = $this->execute($args, $dir, $mustRun);
        $array = array_map(
            function ($element) {
                return trim($element, ' *');
            },
            explode("\n", $mergedBranches)
        );

        return $array;
    }

    /**
     * Execute a Git command.
     *
     * @param string[]          $args
     *   Command arguments (everything after 'git').
     * @param string|false|null $dir
     *   The path to a Git repository. Set to false if the command should not
     *   run inside a repository. Set to null to use the default repository.
     * @param bool              $mustRun
     *   Enable exceptions if the Git command fails.
     * @param bool              $quiet
     *   Suppress command output.
     * @param array             $env
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If the command fails and $mustRun is enabled.
     *
     * @return string|bool
     *   The command output, true if there is no output, or false if the command
     *   fails.
     */
    public function execute(array $args, $dir = null, bool $mustRun = false, bool $quiet = true, array $env = [])
    {
        // If enabled, set the working directory to the repository.
        if ($dir !== false) {
            $dir = $dir ?: $this->repositoryDir;
        }
        // Run the command.
        array_unshift($args, 'git');

        return $this->shellHelper->execute($args, $dir, $mustRun, $quiet, $env + $this->env);
    }

    /**
     * Create a Git repository in a directory.
     *
     * @param string $dir
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function init(string $dir, bool $mustRun = false): bool
    {
        if (is_dir($dir . '/.git')) {
            throw new \InvalidArgumentException("Already a repository: $dir");
        }

        return (bool) $this->execute(['init'], $dir, $mustRun, false);
    }

    /**
     * Check whether a remote repository exists.
     *
     * @param string $url
     * @param string $ref
     * @param bool   $heads Whether to limit to heads only.
     *
     * @throws \Symfony\Component\Process\Exception\RuntimeException
     *   If the Git command fails.
     *
     * @return bool
     */
    public function remoteRefExists(string $url, ?string $ref = null, bool $heads = true): bool
    {
        $args = ['ls-remote', $url];
        if ($heads) {
            $args[] = '--heads';
        }
        if ($ref !== null) {
            $args[] = $ref;
        }
        $result = $this->execute($args, false, true);

        return !is_bool($result) && strlen($result) > 0;
    }

    /**
     * Check whether a branch exists.
     *
     * @param string $branchName
     *   The branch name.
     * @param string $dir
     *   The path to a Git repository.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function branchExists(string $branchName, ?string $dir = null, bool $mustRun = false): bool
    {
        // The porcelain command 'git branch' is less strict about character
        // encoding than (otherwise simpler) plumbing commands such as
        // 'git show-ref'.
        $result = $this->execute(['branch', '--list', '--no-color', '--no-column'], $dir, $mustRun);
        $branches = array_map(function ($line) {
            return trim(ltrim($line, '* '));
        }, explode("\n", $result));

        return in_array($branchName, $branches, true);
    }

    /**
     * Check whether a branch exists on a remote.
     *
     * @param string      $remote
     * @param string      $branchName
     * @param string|null $dir
     * @param bool        $mustRun
     *
     * @return bool
     */
    public function remoteBranchExists(string $remote, string $branchName, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['ls-remote', $remote, $branchName];
        $result = $this->execute($args, $dir, $mustRun);

        return is_string($result) && strlen(trim($result));
    }

    /**
     * Create a new branch and check it out.
     *
     * @param string      $name
     * @param string|null $parent
     * @param string|null $upstream
     * @param string|null $dir     The path to a Git repository.
     * @param bool        $mustRun Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function checkOutNew(string $name, ?string $parent = null, ?string $upstream = null, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['checkout', '-b', $name];
        if ($parent !== null) {
            $args[] = $parent;
        } elseif ($upstream !== null) {
            $args[] = '--track';
            $args[] = $upstream;
        }

        return (bool) $this->execute($args, $dir, $mustRun, false);
    }

    /**
     * Fetch from the Git remote.
     *
     * @param string      $remote
     * @param string|null $branch
     * @param string|null $dir
     * @param bool        $mustRun
     *
     * @return bool
     */
    public function fetch(string $remote, ?string $branch = null, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['fetch', $remote];
        if ($branch !== null) {
            $args[] = $branch;
        }

        return (bool) $this->execute($args, $dir, $mustRun, false);
    }

    /**
     * Check out a branch.
     *
     * @param string      $name
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     * @param bool        $quiet
     *
     * @return bool
     */
    public function checkOut(string $name, ?string $dir = null, bool $mustRun = false, bool $quiet = false): bool
    {
        return (bool) $this->execute([
            'checkout',
            $name,
        ], $dir, $mustRun, $quiet);
    }

    /**
     * Get the upstream for a branch.
     *
     * @param string      $branch
     *   The name of the branch to get the upstream for. Defaults to the current
     *   branch.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     *   The upstream, in the form remote/branch, or false if no upstream is
     *   found.
     */
    public function getUpstream(string $branch = null, ?string $dir = null, bool $mustRun = false)
    {
        if ($branch === null) {
            $args = ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'];

            return $this->execute($args, $dir, $mustRun);
        }

        $remoteName = $this->getConfig("branch.$branch.remote", $dir, $mustRun);
        $remoteBranch = $this->getConfig("branch.$branch.merge", $dir, $mustRun);
        if (empty($remoteName) || empty($remoteBranch)) {
            return false;
        }

        return $remoteName . '/' . str_replace('refs/heads/', '', $remoteBranch);
    }

    /**
     * Set the upstream for the current branch.
     *
     * @param string|false $upstream
     *   The upstream name, or false to unset the upstream.
     * @param string|null  $branch
     *   The branch to act on (defaults to the current branch).
     * @param string|null  $dir
     *   The path to a Git repository.
     * @param bool         $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function setUpstream(string $upstream, ?string $branch = null, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['branch'];
        if ($upstream !== false) {
            $args[] = '--set-upstream-to=' . $upstream;
        } else {
            $args[] = '--unset-upstream';
        }
        if ($branch !== null) {
            $args[] = $branch;
        }

        return (bool) $this->execute($args, $dir, $mustRun);
    }

    /**
     * @return bool
     */
    public function supportsGitSshCommand(): bool
    {
        return version_compare($this->getVersion(), '2.3', '>=');
    }

    /**
     * @return bool
     */
    public function supportsShallowClone(): bool
    {
        return version_compare($this->getVersion(), '1.9', '>=');
    }

    /**
     * Clone a repository.
     *
     * A ProcessFailedException will be thrown if the command fails.
     *
     * @param string $url
     *   The Git repository URL.
     * @param string $destination
     *   A directory name to clone into.
     * @param array  $args
     *   Extra arguments for the Git command.
     * @param bool   $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function cloneRepo(string $url, string $destination = null, array $args = [], bool $mustRun = false): bool
    {
        $args = array_merge(['clone', $url], $args);
        if ($destination) {
            $args[] = $destination;
        }

        return (bool) $this->execute($args, false, $mustRun, false);
    }

    /**
     * Find the root of a directory in a Git repository.
     *
     * @param string|null $dir
     * @param bool        $mustRun
     *
     * @return string|false
     */
    public function getRoot(?string $dir = null, bool $mustRun = false)
    {
        return $this->execute(['rev-parse', '--show-toplevel'], $dir, $mustRun);
    }

    /**
     * Check whether a file is excluded via .gitignore or similar configuration.
     *
     * @param string      $file
     * @param string|null $dir
     *
     * @return bool
     */
    public function checkIgnore(string $file, ?string $dir = null): bool
    {
        return (bool) $this->execute(['check-ignore', $file], $dir);
    }

    /**
     * Update and/or initialize submodules.
     *
     * @param bool        $recursive
     *   Whether to recurse into nested submodules.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return bool
     */
    public function updateSubmodules(bool $recursive = false, ?string $dir = null, bool $mustRun = false): bool
    {
        $args = ['submodule', 'update', '--init'];
        if ($recursive) {
            $args[] = '--recursive';
        }

        return (bool) $this->execute($args, $dir, $mustRun, false);
    }

    /**
     * Read a configuration item.
     *
     * @param string      $key
     *   A Git configuration key.
     * @param string|null $dir
     *   The path to a Git repository.
     * @param bool        $mustRun
     *   Enable exceptions if the Git command fails.
     *
     * @return string|false
     */
    public function getConfig(string $key, ?string $dir = null, bool $mustRun = false)
    {
        $args = ['config', '--get', $key];

        return $this->execute($args, $dir, $mustRun);
    }

    /**
     * Set the SSH command for Git to use.
     *
     * This will use GIT_SSH_COMMAND if supported, or GIT_SSH (and a temporary
     * file) otherwise.
     *
     * @param string|null $sshCommand
     *   The complete SSH command. An empty string or null will use Git's
     *   default.
     */
    public function setSshCommand(?string $sshCommand): void
    {
        if (empty($sshCommand) || $sshCommand === 'ssh') {
            unset($this->env['GIT_SSH_COMMAND'], $this->env['GIT_SSH']);
        } elseif (!$this->supportsGitSshCommand()) {
            $this->env['GIT_SSH'] = $this->writeSshFile($sshCommand . ' "$@"' . "\n");
        } else {
            $this->env['GIT_SSH_COMMAND'] = $sshCommand;
        }
    }

    /**
     * Write an SSH command to a temporary file to be used with GIT_SSH.
     *
     * @param string $sshCommand
     *
     * @return string
     */
    public function writeSshFile(string $sshCommand): string
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'cli-git-ssh');
        if (!$tempFile) {
            throw new \RuntimeException('Failed to create temporary file in: ' . $tempDir);
        }
        if (!file_put_contents($tempFile, trim($sshCommand) . "\n")) {
            throw new \RuntimeException('Failed to write temporary file: ' . $tempFile);
        }
        if (!chmod($tempFile, 0750)) {
            throw new \RuntimeException('Failed to make temporary SSH command file executable: ' . $tempFile);
        }

        $this->sshCommandFile = $tempFile;

        return $tempFile;
    }

    public function __destruct()
    {
        if (isset($this->sshCommandFile)) {
            if (@unlink($this->sshCommandFile)) {
                unset($this->sshCommandFile);
            }
        }
    }
}
