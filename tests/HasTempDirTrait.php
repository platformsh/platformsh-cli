<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

trait HasTempDirTrait
{
    protected $tempDir;

    protected function tempDirSetUp()
    {
        if (!isset($this->tempDir)) {
            $this->tempDir = $this->createTempDir(sys_get_temp_dir(), 'pshCliTmp');
        }
    }

    /**
     * @param string $parentDir
     * @param string $prefix
     *
     * @return string
     */
    protected function createTempDir($parentDir, $prefix = '')
    {
        if (!($tempDir = tempnam($parentDir, $prefix))
          || !unlink($tempDir)
          || !mkdir($tempDir, 0755)) {
            throw new \RuntimeException('Failed to create temporary directory in: ' . $parentDir);
        }

        return $tempDir;
    }

    /**
     * @param string $prefix
     *
     * @return string
     */
    protected function createTempSubDir($prefix = '')
    {
        $this->tempDirSetUp();

        return $this->createTempDir($this->tempDir, $prefix);
    }

    public function tearDown()
    {
        if (!empty($this->tempDir)) {
            exec('rm -Rf ' . escapeshellarg($this->tempDir));
        }
    }
}
