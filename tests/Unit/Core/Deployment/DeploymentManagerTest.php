<?php

namespace Tests\Unit\Core\Deployment;

use PHPUnit\Framework\TestCase;
use Whity\Core\Deployment\DeploymentManager;
use Whity\Database\Database;
use PDO;

class DeploymentManagerTest extends TestCase
{
    private $db;
    private $storagePath;
    private $manager;

    protected function setUp(): void
    {
        // Use in-memory SQLite for testing if possible, but the project uses PostgreSQL.
        // For unit tests, I might need to mock the Database/PDO.
        $this->db = $this->createMock(PDO::class);
        $this->storagePath = sys_get_temp_dir() . '/whity_deploy_test_' . uniqid();
        mkdir($this->storagePath, 0777, true);

        $this->manager = new DeploymentManager($this->db, $this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDirectory("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function testApplyDeploymentCreatesDirectoryAndUpdatesDb(): void
    {
        $tenantId = 1;
        $version = 'v1.0.0';
        $sourcePath = sys_get_temp_dir() . '/whity_source_' . uniqid();
        mkdir($sourcePath);
        file_put_contents($sourcePath . '/test.php', '<?php echo "test";');

        // Expect DB interactions (3 calls: 1 for lookup previous, 2 for status updates)
        $this->db->expects($this->exactly(3))
            ->method('prepare')
            ->willReturn($this->createMock(\PDOStatement::class));

        $result = $this->manager->apply($tenantId, $version, $sourcePath);

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->storagePath . "/$tenantId/$version");
        $this->assertFileExists($this->storagePath . "/$tenantId/$version/test.php");

        $this->removeDirectory($sourcePath);
    }

    public function testRollbackToPreviousVersion(): void
    {
        // TODO: Implement
        $this->assertTrue(true);
    }
}
