<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Git;

/**
 * @group slow
 */
class GitServiceTest extends TestCase
{

    use HasTempDirTrait;

    /** @var Git */
    protected $git;

    /**
     * @{inheritdoc}
     *
     * Set up before every test.
     *
     * @throws \Exception
     */
    public function setUp()
    {
        $this->tempDirSetUp();
        $repository = $this->getRepositoryDir();
        if (!is_dir($repository) && !mkdir($repository, 0755, true)) {
            throw new \Exception("Failed to create directories.");
        }

        $this->git = new Git();
        $this->git->init($repository, true);
        $this->git->setDefaultRepositoryDir($repository);
        chdir($repository);

        // Ensure we are on the master branch.
        $this->git->checkOut('master');

        // Add required Git config before committing.
        shell_exec('git config user.email test@example.com');
        shell_exec('git config user.name "Test"');
        shell_exec('git config commit.gpgsign false');

        // Make a dummy commit so that there is a HEAD.
        touch($repository . '/README.txt');
        shell_exec('git add -A && git commit -qm "Initial commit"');
    }

    /**
     * Test GitHelper::isRepository().
     */
    public function testGetRoot()
    {
        $this->assertFalse($this->git->getRoot($this->tempDir));
        $repositoryDir = $this->getRepositoryDir();
        $this->assertEquals($repositoryDir, $this->git->getRoot($repositoryDir));
        mkdir($repositoryDir . '/1/2/3/4/5', 0755, true);
        $this->assertEquals($repositoryDir, $this->git->getRoot($repositoryDir . '/1/2/3/4/5'));
        $this->expectExceptionMessage('Not a git repository');
        $this->git->getRoot($this->tempDir, true);
    }

    /**
     * Get a Git repository directory.
     *
     * @return string
     */
    protected function getRepositoryDir()
    {
        return $this->tempDir . '/repo';
    }

    /**
     * Test GitHelper::checkOutNew().
     */
    public function testCheckOutNew()
    {
        $this->assertTrue($this->git->checkOutNew('new'));
        $this->git->checkOut('master');
    }

    /**
     * Test GitHelper::branchExists().
     */
    public function testBranchExists()
    {
        $this->git->checkOutNew('existent');
        $this->assertTrue($this->git->branchExists('existent'));
        $this->assertFalse($this->git->branchExists('nonexistent'));
    }

    /**
     * Test GitHelper::branchExists() with unicode branch names.
     */
    public function testBranchExistsUnicode()
    {
        $this->git->checkOutNew('b®åñçh-wî†h-üní¢ø∂é');
        $this->assertTrue($this->git->branchExists('b®åñçh-wî†h-üní¢ø∂é'));
    }

    /**
     * Test GitHelper::getCurrentBranch().
     */
    public function testGetCurrentBranch()
    {
        $this->git->checkOutNew('test');
        $this->assertEquals('test', $this->git->getCurrentBranch());
    }

    /**
     * Test GitHelper::getMergedBranches().
     */
    public function testGetMergedBranches()
    {
        $this->git->checkOutNew('branch1');
        $this->git->checkOutNew('branch2');
        $this->assertEquals([
            'branch1',
            'branch2',
            'master',
        ], $this->git->getMergedBranches('master'));
    }

    /**
     * Test GitHelper::getConfig().
     */
    public function testGetConfig()
    {
        $config = $this->git->getConfig('user.email');
        $this->assertEquals('test@example.com', $config);
    }

}
