<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../homeMount.php';

/** Characterization tests for the /home mount guard helper. */
class HomeMountTest extends TestCase
{
    public function testIsHomeMountedParsesRealMountsFile(): void
    {
        $this->stageMounts(<<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
/dev/sdb1 /home ext4 rw,relatime 0 0
tmpfs /tmp tmpfs rw,nosuid,nodev 0 0
MOUNTS
        );

        $this->assertTrue(pmssIsHomeMounted());
    }

    public function testIsHomeMountedReturnsFalseWhenHomeNotInMounts(): void
    {
        $this->stageMounts(<<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
tmpfs /tmp tmpfs rw,nosuid,nodev 0 0
MOUNTS
        );

        $this->assertFalse(pmssIsHomeMounted());
    }

    public function testIsHomeMountedDoesNotMatchSubpaths(): void
    {
        $this->stageMounts(<<<MOUNTS
/dev/sda1 / ext4 rw,relatime 0 0
/dev/sdc1 /home/special ext4 rw,relatime 0 0
MOUNTS
        );

        $this->assertFalse(pmssIsHomeMounted());
    }

    public function testIsHomeMountedReturnsFalseWhenMountsFileUnreadable(): void
    {
        $this->pmssTrackEnvOverrides(['PMSS_PROC_MOUNTS_PATH' => '/nonexistent/path/to/mounts']);

        $this->assertFalse(pmssIsHomeMounted());
    }

    public function testRequireHomeMountedSkipsWhenEnvSet(): void
    {
        $this->stageMissingMounts('1');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testRequireHomeMountedSkipsWhenEnvSetToTrue(): void
    {
        $this->stageMissingMounts('true');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testRequireHomeMountedSkipsWhenEnvSetToUppercaseTrue(): void
    {
        $this->stageMissingMounts('TRUE');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testRequireHomeMountedPassesWhenMounted(): void
    {
        $this->stageMounts("/dev/sdb1 /home ext4 rw,relatime 0 0\n", '');

        pmssRequireHomeMounted('test');
        $this->assertTrue(true);
    }

    public function testMountsFileWithVariousFormats(): void
    {
        $mountsPath = $this->stageMounts("/dev/md0    /home    ext4    rw,relatime,data=ordered    0    0\n");
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home with extra spaces');

        file_put_contents($mountsPath, "/dev/md0\t/home\text4\trw,relatime\t0\t0\n");
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home with tabs');

        file_put_contents($mountsPath, "server:/export/home /home nfs4 rw,relatime,vers=4.2 0 0\n");
        $this->assertTrue(pmssIsHomeMounted(), 'Should match /home on NFS');
    }

    private function stageMounts(string $content, ?string $skip = null): string
    {
        $path = $this->pmssMakeTempPath('pmss-test-mounts-');
        file_put_contents($path, $content);
        $env = ['PMSS_PROC_MOUNTS_PATH' => $path];
        if ($skip !== null) {
            $env['PMSS_SKIP_HOME_MOUNT_CHECK'] = $skip;
        }
        $this->pmssTrackEnvOverrides($env);
        return $path;
    }

    private function stageMissingMounts(string $skip): void
    {
        $this->pmssTrackEnvOverrides([
            'PMSS_SKIP_HOME_MOUNT_CHECK' => $skip,
            'PMSS_PROC_MOUNTS_PATH' => '/nonexistent/path/to/mounts',
        ]);
    }
}
