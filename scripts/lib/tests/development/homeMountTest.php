<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../homeMount.php';

/**
 * Tests for the /home mount guard helper.
 *
 * These tests use environment variable overrides to simulate different mount
 * states without requiring actual filesystem changes.
 */
class HomeMountTest extends TestCase
{
    private $originalOverride;
    private $originalSkip;
    private $originalMountsPath;

    protected function setUp(): void
    {
        // Preserve original env state.
        $this->originalOverride = getenv('PMSS_HOME_MOUNTED_OVERRIDE');
        $this->originalSkip = getenv('PMSS_SKIP_HOME_MOUNT_CHECK');
        $this->originalMountsPath = getenv('PMSS_PROC_MOUNTS_PATH');
    }

    protected function tearDown(): void
    {
        // Restore original env state.
        if ($this->originalOverride === false) {
            putenv('PMSS_HOME_MOUNTED_OVERRIDE');
        } else {
            putenv('PMSS_HOME_MOUNTED_OVERRIDE='.$this->originalOverride);
        }
        if ($this->originalSkip === false) {
            putenv('PMSS_SKIP_HOME_MOUNT_CHECK');
        } else {
            putenv('PMSS_SKIP_HOME_MOUNT_CHECK='.$this->originalSkip);
        }
        if ($this->originalMountsPath === false) {
            putenv('PMSS_PROC_MOUNTS_PATH');
        } else {
            putenv('PMSS_PROC_MOUNTS_PATH='.$this->originalMountsPath);
        }
    }

    public function testIsHomeMountedReturnsTrueWhenOverrideSet(): void
    {
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=1');
        $this->assertTrue(pmssIsHomeMounted());

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=true');
        $this->assertTrue(pmssIsHomeMounted());
    }

    public function testIsHomeMountedReturnsFalseWhenOverrideSetToFalse(): void
    {
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=0');
        $this->assertTrue(!pmssIsHomeMounted());

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=false');
        $this->assertTrue(!pmssIsHomeMounted());
    }

    public function testIsHomeMountedNormalizesOverrideCase(): void
    {
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=TRUE');
        $this->assertTrue(pmssIsHomeMounted());

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=FALSE');
        $this->assertTrue(!pmssIsHomeMounted());
    }

    public function testIsHomeMountedParsesRealMountsFile(): void
    {
        // Create a temporary mounts file with /home mounted.
        $tmpFile = sys_get_temp_dir().'/pmss-test-mounts-'.getmypid();
        $content = <<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
/dev/sdb1 /home ext4 rw,relatime 0 0
tmpfs /tmp tmpfs rw,nosuid,nodev 0 0
MOUNTS;
        file_put_contents($tmpFile, $content);

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=');
        putenv('PMSS_PROC_MOUNTS_PATH='.$tmpFile);

        $this->assertTrue(pmssIsHomeMounted());

        unlink($tmpFile);
    }

    public function testIsHomeMountedReturnsFalseWhenHomeNotInMounts(): void
    {
        // Create a temporary mounts file without /home.
        $tmpFile = sys_get_temp_dir().'/pmss-test-mounts-'.getmypid();
        $content = <<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
tmpfs /tmp tmpfs rw,nosuid,nodev 0 0
MOUNTS;
        file_put_contents($tmpFile, $content);

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=');
        putenv('PMSS_PROC_MOUNTS_PATH='.$tmpFile);

        $this->assertTrue(!pmssIsHomeMounted());

        unlink($tmpFile);
    }

    public function testIsHomeMountedDoesNotMatchSubpaths(): void
    {
        // Ensure /home/user does not match as /home mount.
        $tmpFile = sys_get_temp_dir().'/pmss-test-mounts-'.getmypid();
        $content = <<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
/dev/sdc1 /home/special ext4 rw,relatime 0 0
MOUNTS;
        file_put_contents($tmpFile, $content);

        putenv('PMSS_HOME_MOUNTED_OVERRIDE=');
        putenv('PMSS_PROC_MOUNTS_PATH='.$tmpFile);

        $this->assertTrue(!pmssIsHomeMounted());

        unlink($tmpFile);
    }

    public function testIsHomeMountedReturnsFalseWhenMountsFileUnreadable(): void
    {
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=');
        putenv('PMSS_PROC_MOUNTS_PATH=/nonexistent/path/to/mounts');

        $this->assertTrue(!pmssIsHomeMounted());
    }

    public function testRequireHomeMountedSkipsWhenEnvSet(): void
    {
        // When skip is set, the function should return without exiting.
        putenv('PMSS_SKIP_HOME_MOUNT_CHECK=1');
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=0'); // Force "not mounted"

        // If this doesn't exit, the test passes.
        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testRequireHomeMountedSkipsWhenEnvSetToTrue(): void
    {
        putenv('PMSS_SKIP_HOME_MOUNT_CHECK=true');
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=0');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testRequireHomeMountedPassesWhenMounted(): void
    {
        putenv('PMSS_SKIP_HOME_MOUNT_CHECK=');
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=1');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testMountsFileWithVariousFormats(): void
    {
        // Test with different mount line formats.
        $tmpFile = sys_get_temp_dir().'/pmss-test-mounts-'.getmypid();

        // Format with extra spaces.
        $content = "/dev/md0    /home    ext4    rw,relatime,data=ordered    0    0\n";
        file_put_contents($tmpFile, $content);
        putenv('PMSS_HOME_MOUNTED_OVERRIDE=');
        putenv('PMSS_PROC_MOUNTS_PATH='.$tmpFile);
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home with extra spaces');

        // Format with tabs.
        $content = "/dev/md0\t/home\text4\trw,relatime\t0\t0\n";
        file_put_contents($tmpFile, $content);
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home with tabs');

        // NFS mount format.
        $content = "server:/export/home /home nfs4 rw,relatime,vers=4.2 0 0\n";
        file_put_contents($tmpFile, $content);
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home on NFS');

        unlink($tmpFile);
    }
}
