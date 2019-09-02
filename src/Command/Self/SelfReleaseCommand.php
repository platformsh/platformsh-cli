<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use GuzzleHttp\Client;
use function GuzzleHttp\json_decode;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SubCommandRunner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

class SelfReleaseCommand extends CommandBase
{
    protected static $defaultName = 'self:release';

    private $config;
    private $git;
    private $questionHelper;
    private $shell;
    private $subCommandRunner;

    public function __construct(
        Config $config,
        Git $git,
        Shell $shell,
        QuestionHelper $questionHelper,
        SubCommandRunner $subCommandRunner
    ) {
        $this->config = $config;
        $this->git = $git;
        $this->questionHelper = $questionHelper;
        $this->shell = $shell;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct();
    }

    protected function configure()
    {
        $defaultRepo = $this->config->getWithDefault('application.github_repo', null);
        $defaultReleaseBranch = $this->config->getWithDefault('application.release_branch', 'master');

        $this->setDescription('Build and release a new version')
            ->addArgument('version', InputArgument::OPTIONAL, 'The new version number')
            ->addOption('phar', null, InputOption::VALUE_REQUIRED, 'The path to a newly built Phar file')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'The GitHub repository', $defaultRepo)
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'The manifest file to update')
            ->addOption('manifest-mode', null, InputOption::VALUE_REQUIRED, 'How to update the manifest file', 'update-latest-matching')
            ->addOption('release-branch', null, InputOption::VALUE_REQUIRED, 'Override the release branch', $defaultReleaseBranch)
            ->addOption('last-version', null, InputOption::VALUE_REQUIRED, 'The last version number')
            ->addOption('no-check-changes', null, InputOption::VALUE_NONE, 'Skip check for uncommitted changes, or no change since the last version')
            ->addOption('allow-lower', null, InputOption::VALUE_NONE, 'Allow releasing with a lower version number than the last');

        $this->setHidden(true);
    }

    public function isEnabled()
    {
        return $this->config->has('application.github_repo')
            && (!extension_loaded('Phar') || !\Phar::running(false));
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->git->setDefaultRepositoryDir(CLI_ROOT);

        $releaseBranch = $input->getOption('release-branch');
        if ($this->git->getCurrentBranch(CLI_ROOT, true) !== $releaseBranch) {
            $this->stdErr->writeln('You must be on the ' . $releaseBranch . ' branch to make a release.');
            $this->stdErr->writeln('Check out master, or use the --release-branch option to override this.');

            return 1;
        }

        if (!$input->getOption('no-check-changes')) {
            $gitStatus = $this->git->execute(['status', '--porcelain'], CLI_ROOT, true);
            if (is_string($gitStatus) && !empty($gitStatus)) {
                foreach (explode("\n", $gitStatus) as $statusLine) {
                    if (strpos($statusLine, ' config/config.yaml') === false) {
                        $this->stdErr->writeln('There are uncommitted changes in Git. Cannot proceed.');
                        $this->stdErr->writeln('Use the --no-check-changes option to override this.');

                        return 1;
                    }
                }
            }
        }

        if (getenv('GITHUB_TOKEN')) {
            $gitHubToken = getenv('GITHUB_TOKEN');
        } else {
            $this->stdErr->writeln('The GITHUB_TOKEN environment variable must be set');

            return 1;
        }

        // Find the previous version number.
        if ($input->getOption('last-version')) {
            $lastVersion = ltrim($input->getOption('last-version'), 'v');
            $lastTag = 'v' . $lastVersion;

            $this->stdErr->writeln('Last version number: <info>' . $lastVersion . '</info>');
        } else {
            $lastTag = $this->shell->execute(['git', 'describe', '--tags', '--abbrev=0'], CLI_ROOT, true);
            $lastVersion = ltrim($lastTag, 'v');
            $this->stdErr->writeln('Last version number (from latest Git tag): <info>' . $lastVersion . '</info>');
        }

        if (!$input->getOption('no-check-changes') && !$this->hasGitDifferences($lastTag)) {
            $this->stdErr->writeln('There are no changes since the last version.');

            return 1;
        }

        $allowLower = (bool) $input->getOption('allow-lower');
        $validateNewVersion = function (?string $next) use ($lastVersion, $allowLower): string {
            if ($next === null) {
                throw new \InvalidArgumentException('The new version is required.');
            }
            if (!$allowLower && version_compare($next, $lastVersion, '<=')) {
                throw new \InvalidArgumentException(
                    'The new version number must be greater than ' . $lastVersion
                    . "\n" . 'Use --allow-lower to skip this check.'
                );
            }

            return $next;
        };

        $newVersion = $input->getArgument('version');
        if ($newVersion !== null) {
            $validateNewVersion($newVersion);
        } else {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The version number is required in non-interactive mode.');

                return 1;
            }

            // Find a good default new version number.
            $default = null;
            $autoComplete = [];
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(\-.+)?$/', $lastVersion)) {
                $nextPatch = preg_replace_callback('/^([0-9]+\.[0-9]+\.)([0-9]+)(\-[a-z]+)?.*$/', function (array $matches): string {
                    return $matches[1] . ($matches[2] + 1) . $matches[3];
                }, $lastVersion);
                $nextMinor = preg_replace_callback('/^([0-9]+\.)([0-9]+)\.[^\-]+(\-[a-z]+)?.*$/', function (array $matches): string {
                    return $matches[1] . ($matches[2] + 1) . '.0' . $matches[3];
                }, $lastVersion);
                $nextMajor = preg_replace_callback('/^([0-9]+)\.[^\-]+(\-[a-z]+)?.*$/', function (array $matches): string {
                    return ($matches[1] + 1) . '.0.0' . $matches[2];
                }, $lastVersion);
                $default = $nextPatch;
                $autoComplete = [$nextPatch, $nextMinor, $nextMajor];
            }

            $newVersion = $this->questionHelper->askInput('New version number', $default, $autoComplete, $validateNewVersion);
        }

        // Set up GitHub API connection details.
        $http = new Client();
        $repo = $input->getOption('repo') ?: $this->config->get('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $repoApiUrl = 'https://api.github.com/repos/' . $repoUrl;
        $repoGitUrl = 'git@github.com:' . $repo . '.git';

        // Check if the chosen version number already exists as a release.
        $tagName = 'v' . ltrim($newVersion, 'v');
        $existsResponse = $http->get($repoApiUrl . '/releases/tags/' . $tagName, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'exceptions' => false,
            'debug' => $output->isDebug(),
        ]);
        if ($existsResponse->getStatusCode() !== 404) {
            if ($existsResponse->getStatusCode() >= 300) {
                $this->stdErr->writeln('Failed to check for an existing release on GitHub.');

                return 1;
            }
            $this->stdErr->writeln('A release tagged ' . $tagName . ' already exists on GitHub.');

            return 1;
        }

        // Validate the --phar option.
        $pharFilename = $input->getOption('phar');
        if ($pharFilename && !file_exists($pharFilename)) {
            $this->stdErr->writeln('File not found: <error>' . $pharFilename . '</error>');

            return 1;
        }

        // Check the manifest file for the right item to update.
        $manifestFile = $input->getOption('manifest') ?: CLI_ROOT . '/dist/manifest.json';
        $contents = file_get_contents($manifestFile);
        if ($contents === false) {
            throw new \RuntimeException('Manifest file not readable: ' . $manifestFile);
        }
        if (!is_writable($manifestFile)) {
            throw new \RuntimeException('Manifest file not writable: ' . $manifestFile);
        }
        $this->stdErr->writeln('Checking manifest file: ' . $manifestFile);
        $manifest = json_decode($contents, true);
        $latestItem = null;
        $latestSameMajorItem = null;
        foreach ($manifest as $key => $item) {
            if ($latestItem === null || version_compare($item['version'], $latestItem['version'], '>')) {
                $latestItem = &$manifest[$key];
            }
            if ($this->majorVersion($item['version']) === $this->majorVersion($newVersion)) {
                if ($latestSameMajorItem || version_compare($item['version'], $latestSameMajorItem['version'], '>')) {
                    $latestSameMajorItem = &$manifest[$key];
                }
            }
        }
        $manifestItem = null;
        switch ($input->getOption('manifest-mode')) {
            case 'update-latest':
                $manifestItem = &$latestItem;
                break;

            case 'update-latest-matching':
                $manifestItem = &$latestSameMajorItem;
                break;

            case 'add':
                break;

            default:
                throw new \RuntimeException('Unrecognised --manifest-mode: ' . $input->getOption('manifest-mode'));
        }
        if ($manifestItem === null) {
            array_unshift($manifest, []);
            $manifestItem = &$manifest[0];
        }

        // Confirm the release changelog.
        $changelog = $this->getReleaseChangelog($lastTag);
        $questionText = "\nChangelog:\n\n" . $changelog . "\n\nIs this changelog correct?";
        if (!$this->questionHelper->confirm($questionText)) {
            $this->stdErr->writeln('Update or delete the file <comment>' . CLI_ROOT . '/release-changelog.md</comment> and re-run this command.');

            return 1;
        }

        // Build a Phar file, if one doesn't already exist.
        if (!$pharFilename) {
            $pharFilename = sys_get_temp_dir() . '/' . $this->config->get('application.executable') . '.phar';
            $result = $this->subCommandRunner->run('self:build', [
                '--output' => $pharFilename,
                '--yes' => true,
                '--replace-version' => $newVersion,
            ]);
            if ($result !== 0) {
                $this->stdErr->writeln('The build failed');

                return $result;
            }
        }

        // Validate that the Phar file has the right version number.
        if ($pharFilename) {
            $versionInPhar = $this->shell->execute([
                (new PhpExecutableFinder())->find() ?: PHP_BINARY,
                $pharFilename,
                '--version'
            ], null, true);
            if (strpos($versionInPhar, $newVersion) === false) {
                $this->stdErr->writeln('The file ' . $pharFilename . ' reports a different version: "' . $versionInPhar . '"');

                return 1;
            }
        }

        // Construct the download URL (the public location of the Phar file).
        $pharPublicFilename = $this->config->get('application.executable') . '.phar';
        $download_url = str_replace('{tag}', $tagName, $this->config->getWithDefault(
            'application.download_url',
            'https://github.com/' . $repoUrl . '/releases/download/{tag}/' . $pharPublicFilename
        ));

        // Construct the new manifest item details.
        $manifestItem['version'] = $newVersion;
        $manifestItem['sha1'] = sha1_file($pharFilename);
        $manifestItem['sha256'] = hash_file('sha256', $pharFilename);
        $manifestItem['name'] = basename($pharPublicFilename);
        $manifestItem['url'] = $download_url;
        $manifestItem['php']['min'] = '7.1.3';
        if (!empty($changelog)) {
            $manifestItem['updating'][] = [
                'notes' => $changelog,
                'show from' => $lastVersion,
                'hide from' => $newVersion,
            ];
            $this->stdErr->writeln('<info>Changes:</info>');
            $this->stdErr->writeln($changelog);
            $this->stdErr->writeln('');
        }
        $result = file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result !== false) {
            $this->stdErr->writeln('Updated manifest file: ' . $manifestFile);
        }
        else {
            $this->stdErr->writeln('Failed to update manifest file: ' . $manifestFile);

            return 1;
        }

        // Commit any changes to Git.
        $gitStatus = $this->git->execute(['status', '--porcelain'], CLI_ROOT, true);
        if (is_string($gitStatus) && !empty($gitStatus)) {
            $this->stdErr->writeln('Committing changes to Git');

            $result = $this->shell->executeSimple('git commit --patch config/config.yaml dist/manifest.json --message ' . escapeshellarg('Release v' . $newVersion) . ' --edit', CLI_ROOT);
            if ($result !== 0) {
                return $result;
            }
        }

        // Tag the current commit.
        $this->stdErr->writeln('Creating tag <info>' . $tagName . '</info>');
        $this->git->execute(['tag', '--force', $tagName], CLI_ROOT, true);

        // Push to GitHub.
        if (!$this->questionHelper->confirm('Push changes to <comment>' . $releaseBranch . '</comment> branch on ' . $repoGitUrl . '?')) {
            return 1;
        }
        $this->shell->execute(['git', 'push', $repoGitUrl, 'HEAD:' . $releaseBranch], CLI_ROOT, true);
        $this->shell->execute(['git', 'push', '--force', $repoGitUrl, $tagName], CLI_ROOT, true);

        // Upload a release to GitHub.
        $lastReleasePublicUrl = 'https://github.com/' . $repoUrl . '/releases/' . $lastTag;
        $releaseDescription = sprintf('Changes since [%s](%s):', $lastTag, $lastReleasePublicUrl);
        if (!empty($changelog)) {
            $releaseDescription .= "\n\n" . $changelog;
        }
        $releaseDescription .= "\n\n" . 'https://github.com/' . $repoUrl . '/compare/' . $lastTag . '...' . $tagName;
        $releaseDescription .= "\n\n" . sprintf('SHA-256 checksum for `%s`:', $pharPublicFilename)
            . "\n" . sprintf('`%s`', hash_file('sha256', $pharFilename));
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Creating new release ' . $tagName . ' on GitHub');
        $this->stdErr->writeln('Release description:');
        $this->stdErr->writeln(preg_replace('/^/m', '  ', $releaseDescription));
        $this->stdErr->writeln('');
        $http = new Client();
        $response = $http->post($repoApiUrl . '/releases', [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'tag_name' => $tagName,
                'name' => $tagName,
                'body' => $releaseDescription,
                'draft' => true,
                'prerelease' => $this->isPreRelease($newVersion),
            ],
            'debug' => $output->isDebug(),
        ]);
        $release = json_decode($response->getBody()->getContents(), true);
        $releaseUrl = $repoApiUrl . '/releases/' . $release['id'];
        $uploadUrl = preg_replace('/\{.+?\}/', '', $release['upload_url']);

        // Upload the Phar to the GitHub release.
        $this->stdErr->writeln('Uploading the Phar file to the release');
        $fileResource = fopen($pharFilename, 'r');
        if (!$fileResource) {
            throw new \RuntimeException('Failed to open file for reading: ' . $fileResource);
        }
        $http->post($uploadUrl . '?name=' . rawurldecode($pharPublicFilename), [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $fileResource,
            'debug' => $output->isDebug(),
        ]);

        // Mark the GitHub release as published.
        $this->stdErr->writeln('Publishing the release');
        $http->patch($releaseUrl, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => ['draft' => false],
            'debug' => $output->isDebug(),
        ]);
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Release successfully published');
        $this->stdErr->writeln('https://github.com/' . $repoUrl . '/releases/latest');

        return 0;
    }

    /**
     * @param string $lastVersionTag The tag corresponding to the last version.
     *
     * @return string
     */
    private function getReleaseChangelog(string $lastVersionTag): string
    {
        $filename = CLI_ROOT . '/release-changelog.md';
        if (file_exists($filename)) {
            $mTime = filemtime($filename);
            $lastVersionDate = $this->getTagDate($lastVersionTag);
            if (!$lastVersionDate || !$mTime || $mTime > $lastVersionDate) {
                $contents = file_get_contents($filename);
                if ($contents === false) {
                    throw new \RuntimeException('Failed to read file: ' . $filename);
                }
                $changelog = trim($contents);
            }
        }
        if (empty($changelog)) {
            $changelog = $this->getGitChangelog($lastVersionTag);
            (new Filesystem())->dumpFile($filename, $changelog);
        }

        return $changelog;
    }

    /**
     * Returns the commit date associated with a tag.
     *
     * @param string $tagName
     *
     * @return int|false
     */
    private function getTagDate(string $tagName)
    {
        $date = $this->git->execute(['log', '-1', '--format=%aI', 'refs/tags/' . $tagName]);

        return is_string($date) ? strtotime(trim($date)) : false;
    }

    /**
     * Checks if there are relevant Git differences since the last version.
     *
     * @param string      $since
     *
     * @return bool
     */
    private function hasGitDifferences($since)
    {
        $stat = $this->git->execute(['diff', '--numstat', $since . '...HEAD'], CLI_ROOT, true);
        if (!is_string($stat)) {
            return false;
        }

        foreach (explode("\n", trim($stat)) as $line) {
            // Exclude config.yaml and dist/manifest.json from the check.
            if (strpos($line, ' config.yaml') === false && strpos($line, ' dist/manifest.json') === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $since
     *
     * @return string
     */
    private function getGitChangelog(string $since): string
    {
        $changelog = $this->git->execute([
            'log',
            '--pretty=tformat:* %s%n%b',
            '--no-merges',
            '--invert-grep',
            '--grep=(Release v|\[skip changelog\])',
            '--perl-regexp',
            '--regexp-ignore-case',
            $since . '...HEAD'
        ], CLI_ROOT);
        if (!is_string($changelog)) {
            return '';
        }

        $changelog = preg_replace('/^[^\*\n]/m', '    $0', $changelog);
        $changelog = preg_replace('/\n+\*/', "\n*", $changelog);
        $changelog = trim($changelog);

        return $changelog;
    }

    /**
     * Check if a version number is for a pre-release version.
     *
     * @param string $version
     *
     * @return bool
     */
    private function isPreRelease(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?\-.+/', $version) === 1;
    }

    /**
     * @param string $version
     *
     * @return int
     */
    private function majorVersion($version)
    {
        if ($dotPos = strpos($version, '.', 1)) {
            $major = substr($version, 0, $dotPos);
            if (is_numeric($major)) {
                return (int) $major;
            }
        } elseif (is_numeric($version)) {
            return (int) $version;
        }

        throw new \RuntimeException('Failed to find major version for: ' . $version);
    }
}
