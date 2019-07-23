<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Yaml\Yaml;

class Filesystem
{

    protected $relative = false;
    protected $fs;
    protected $copyOnWindows = false;

    /** @var Shell */
    protected $shell;

    public function getName()
    {
        return 'fs';
    }

    /**
     * @param Shell|null             $shell
     * @param SymfonyFilesystem|null $fs
     */
    public function __construct(Shell $shell = null, SymfonyFilesystem $fs = null)
    {
        $this->shell = $shell ?: new Shell();
        $this->fs = $fs ?: new SymfonyFilesystem();
    }

    /**
     * @param bool $copyOnWindows
     */
    public function setCopyOnWindows($copyOnWindows = true)
    {
        $this->copyOnWindows = $copyOnWindows;
    }

    /**
     * Set whether to use relative links.
     *
     * @param bool $relative
     */
    public function setRelativeLinks($relative = true)
    {
        // This is not possible on Windows.
        if (OsUtil::isWindows()) {
            $relative = false;
        }
        $this->relative = $relative;
    }

    /**
     * Delete a file or directory.
     *
     * @param string|array|\Traversable $files
     *   A filename, an array of files, or a \Traversable instance to delete.
     * @param bool   $retryWithChmod
     *   Whether to retry deleting on error, after recursively changing file
     *   modes to add read/write/exec permissions. A bit like 'rm -rf'.
     *
     * @return bool
     */
    public function remove($files, $retryWithChmod = false)
    {
        try {
            $this->fs->remove($files);
        } catch (IOException $e) {
            if ($retryWithChmod && $this->unprotect($files, true)) {
                return $this->remove($files, false);
            }
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Make files writable by the current user.
     *
     * @param string|array|\Traversable $files
     *   A filename, an array of files, or a \Traversable instance.
     * @param bool $recursive
     *   Whether to change the mode recursively or not.
     *
     * @return bool
     *   True on success, false on failure.
     */
    protected function unprotect($files, $recursive = false)
    {
        if (!$files instanceof \Traversable) {
            $files = new \ArrayObject(is_array($files) ? $files : array($files));
        }

        foreach ($files as $file) {
            if (is_link($file)) {
                continue;
            } elseif (is_dir($file)) {
                if ((!is_executable($file) || !is_writable($file))
                    && true !== @chmod($file, 0700)) {
                    return false;
                }
                if ($recursive && !$this->unprotect(new \FilesystemIterator($file), true)) {
                    return false;
                }
            } elseif (!is_writable($file) && true !== @chmod($file, 0600)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string The absolute path to the user's home directory
     */
    public static function getHomeDirectory()
    {
        foreach (['HOME', 'USERPROFILE'] as $envVar) {
            if ($value = getenv($envVar)) {
                if (!is_dir($value)) {
                    throw new \RuntimeException(
                        sprintf('Invalid environment variable %s: %s (not a directory)', $envVar, $value)
                    );
                }

                return $value;
            }
        }

        throw new \RuntimeException('Could not determine home directory');
    }

    /**
     * @param string $dir
     * @param int $mode
     */
    public function mkdir($dir, $mode = 0755)
    {
        $this->fs->mkdir($dir, $mode);
    }

    /**
     * Copy a file, if it is newer than the destination.
     *
     * @param string $source
     * @param string $destination
     * @param bool   $override
     */
    public function copy($source, $destination, $override = false)
    {
        if (is_dir($destination) && !is_dir($source)) {
            $destination = rtrim($destination, '/') . '/' . basename($source);
        }
        $this->fs->copy($source, $destination, $override);
    }

    /**
     * Copy all files and folders between directories.
     *
     * @param string $source
     * @param string $destination
     * @param array  $skip
     * @param bool   $override
     */
    public function copyAll($source, $destination, array $skip = ['.git', '.DS_Store'], $override = false)
    {
        if (is_dir($source) && !is_dir($destination)) {
            if (!mkdir($destination, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: " . $destination);
            }
        }

        if (is_dir($source)) {
            // Prevent infinite recursion when the destination is inside the
            // source.
            if (strpos($destination, $source) === 0) {
                $relative = str_replace($source, '', $destination);
                $parts = explode('/', ltrim($relative, '/'), 2);
                $skip[] = $parts[0];
            }

            $sourceDirectory = opendir($source);
            while ($file = readdir($sourceDirectory)) {
                // Skip symlinks, '.' and '..', and files in $skip.
                if ($file === '.'
                    || $file === '..'
                    || $this->inBlacklist($file, $skip)
                    || is_link($source . '/' . $file)) {
                    continue;
                }

                // Recurse into directories.
                if (is_dir($source . '/' . $file)) {
                    $this->copyAll($source . '/' . $file, $destination . '/' . $file, $skip, $override);
                    continue;
                }

                // Perform the copy.
                if (is_file($source . '/' . $file)) {
                    $this->fs->copy($source . '/' . $file, $destination . '/' . $file, $override);
                }
            }
            closedir($sourceDirectory);
        } else {
            $this->fs->copy($source, $destination, $override);
        }
    }

    /**
     * Create a symbolic link to a file or directory.
     *
     * @param string $target The target to link to (must already exist).
     * @param string $link   The name of the symbolic link.
     */
    public function symlink($target, $link)
    {
        if (!file_exists($target)) {
            throw new \InvalidArgumentException('Target not found: ' . $target);
        }
        if ($this->relative) {
            $target = $this->makePathRelative($target, dirname($link));
        }
        $this->fs->symlink($target, $link, $this->copyOnWindows);
    }

    /**
     * Wraps Symfony Filesystem's makePathRelative() with enhancements.
     *
     * This ensures both parts of the path are realpaths, if possible, before
     * calculating the relative path. It also trims trailing slashes.
     *
     * @param string $path      An absolute path.
     * @param string $reference The path to which it will be made relative.
     *
     * @see SymfonyFilesystem::makePathRelative()
     *
     * @return string
     *   The $path, relative to the $reference.
     */
    public function makePathRelative($path, $reference)
    {
        $path = realpath($path) ?: $path;
        $reference = realpath($reference) ?: $reference;

        return rtrim($this->fs->makePathRelative($path, $reference), DIRECTORY_SEPARATOR);
    }

    /**
     * Format a path for display (use the relative path if it's simpler).
     *
     * @param string $path
     *
     * @return string
     */
    public function formatPathForDisplay($path)
    {
        $relative = $this->makePathRelative($path, getcwd());
        if (strpos($relative, '../..') === false && strlen($relative) < strlen($path)) {
            return $relative;
        }

        return rtrim(trim($path), '/');
    }

    /**
     * Check if a filename is in the blacklist.
     *
     * @param string   $filename
     * @param string[] $blacklist
     *
     * @return bool
     */
    protected function inBlacklist($filename, array $blacklist)
    {
        foreach ($blacklist as $pattern) {
            if (fnmatch($pattern, $filename, FNM_PATHNAME | FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Symlink or copy all files and folders between two directories.
     *
     * @param string   $source
     * @param string   $destination
     * @param bool     $skipExisting
     * @param bool     $recursive
     * @param string[] $blacklist
     * @param bool     $copy
     *
     * @throws \Exception When a conflict is discovered.
     */
    public function symlinkAll(
        $source,
        $destination,
        $skipExisting = true,
        $recursive = false,
        $blacklist = [],
        $copy = false
    ) {
        if (!is_dir($destination)) {
            mkdir($destination);
        }

        // The symlink won't work if $source is a relative path.
        $source = realpath($source);

        // Files to always skip.
        $skip = ['.git', '.DS_Store'];
        $skip = array_merge($skip, $blacklist);

        $sourceDirectory = opendir($source);
        while ($file = readdir($sourceDirectory)) {
            // Skip symlinks, '.' and '..', and files in $skip.
            if ($file === '.' || $file === '..' || $this->inBlacklist($file, $skip) || is_link($source . '/' . $file)) {
                continue;
            }
            $sourceFile = $source . '/' . $file;
            $linkFile = $destination . '/' . $file;

            if ($recursive && !is_link($linkFile) && is_dir($linkFile) && is_dir($sourceFile)) {
                $this->symlinkAll($sourceFile, $linkFile, $skipExisting, $recursive, $blacklist, $copy);
                continue;
            } elseif (file_exists($linkFile)) {
                if ($skipExisting) {
                    continue;
                } else {
                    throw new \Exception('File exists: ' . $linkFile);
                }
            } elseif (is_link($linkFile)) {
                // This is a broken link. Remove it.
                $this->remove($linkFile);
            }

            if ($copy) {
                $this->copyAll($sourceFile, $linkFile, $blacklist);
            } else {
                $this->symlink($sourceFile, $linkFile);
            }
        }
        closedir($sourceDirectory);
    }

    /**
     * Make a relative path into an absolute one.
     *
     * The realpath() function will only work for existing files, and not for
     * symlinks. This is a more flexible solution.
     *
     * @param string $relativePath
     *
     * @throws \InvalidArgumentException If the parent directory is not found.
     *
     * @return string
     */
    public function makePathAbsolute($relativePath)
    {
        if (file_exists($relativePath) && !is_link($relativePath) && ($realPath = realpath($relativePath))) {
            $absolute = $realPath;
        } else {
            $parent = dirname($relativePath);
            if (!is_dir($parent) || !($parentRealPath = realpath($parent))) {
                throw new \InvalidArgumentException('Directory not found: ' . $parent);
            }
            $basename = basename($relativePath);
            $absolute = $basename == '..'
                ? dirname($parentRealPath)
                : rtrim($parentRealPath . '/' . $basename, './');
        }

        return $absolute;
    }

    /**
     * Test whether a file or directory is writable even if it does not exist.
     *
     * @param string $name
     *
     * @return bool
     */
    public function canWrite($name)
    {
        if (is_writable($name)) {
            return true;
        }

        $current = $name;
        while (!file_exists($current) && ($parent = dirname($current)) && $parent !== $current) {
            if (is_writable($parent)) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Write a file and create a backup if the contents have changed.
     *
     * @param string $filename
     * @param string $contents
     * @param bool   $backup
     */
    public function writeFile($filename, $contents, $backup = true)
    {
        $fs = new SymfonyFilesystem();
        if (file_exists($filename) && $backup && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . basename($filename) . '.bak';
            $fs->rename($filename, $backupName, true);
        }
        $fs->dumpFile($filename, $contents);
    }

    /**
     * Create a gzipped tar archive of a directory's contents.
     *
     * @param string $dir
     * @param string $destination
     */
    public function archiveDir($dir, $destination)
    {
        $tar = $this->getTarExecutable();
        $dir = $this->fixTarPath($dir);
        $destination = $this->fixTarPath($destination);
        $this->shell->execute([$tar, '-czp', '-C' . $dir, '-f' . $destination, '.'], null, true);
    }

    /**
     * Extract a gzipped tar archive into the specified destination directory.
     *
     * @param string $archive
     * @param string $destination
     */
    public function extractArchive($archive, $destination)
    {
        if (!file_exists($archive)) {
            throw new \InvalidArgumentException("Archive not found: $archive");
        }
        if (!file_exists($destination) && !mkdir($destination, 0755, true)) {
            throw new \InvalidArgumentException("Could not create destination directory: $destination");
        }
        $tar = $this->getTarExecutable();
        $destination = $this->fixTarPath($destination);
        $archive = $this->fixTarPath($archive);
        $this->shell->execute([$tar, '-xzp', '-C' . $destination, '-f' . $archive], null, true);
    }

    /**
     * Fix a path so that it can be used with tar on Windows.
     *
     * @see http://betterlogic.com/roger/2009/01/tar-woes-with-windows/
     *
     * @param string $path
     *
     * @return string
     */
    protected function fixTarPath($path)
    {
        if (OsUtil::isWindows()) {
            $path = preg_replace_callback(
                '#^([A-Z]):/#i',
                function (array $matches) {
                    return '/' . strtolower($matches[1]) . '/';
                },
                str_replace('\\', '/', $path)
            );
        }

        return $path;
    }

    /**
     * @return string
     */
    protected function getTarExecutable()
    {
        $candidates = ['tar', 'tar.exe', 'bsdtar.exe'];
        foreach ($candidates as $command) {
            if ($this->shell->commandExists($command)) {
                return $command;
            }
        }
        throw new \RuntimeException("Tar command not found");
    }

    /**
     * @param $filename
     * @return bool
     */
    public function fileExists($filename) {
        return $this->fs->exists($filename);
    }

    /**
     * @param string $filename
     *
     * @return array
     */
    public function readYamlFile($filename)
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read file: ' . $filename);
        }

        return (array) Yaml::parse($contents);
    }

    /**
     * @param $filename
     * @param array $contents
     */
    public function createYamlFile($filename, array $contents) {
        $yaml = Yaml::dump($contents);
        $this->fs->dumpFile($filename, $yaml);
    }
}
