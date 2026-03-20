<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/services/mountHardening.php';

class TempTmpfsMountTest extends TestCase
{
    private $prevFlag;
    private $prevSize;
    private $prevDryRun;

    public function setUp(): void
    {
        $this->prevFlag = getenv('PMSS_HARDEN_TMP_TMPFS');
        $this->prevSize = getenv('PMSS_TMPFS_TMP_SIZE');
        $this->prevDryRun = getenv('PMSS_DRY_RUN');
    }

    public function tearDown(): void
    {
        $this->restoreEnv('PMSS_HARDEN_TMP_TMPFS', $this->prevFlag);
        $this->restoreEnv('PMSS_TMPFS_TMP_SIZE', $this->prevSize);
        $this->restoreEnv('PMSS_DRY_RUN', $this->prevDryRun);
    }

    public function testSkipsWhenFlagDisabled(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-skip');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->messagesContain($messages, 'disabled'), 'expected disabled log');

        $this->cleanup($dir);
    }

    public function testSkipsWhenFlagExplicitlyFalse(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-false');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS=FALSE');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string) file_get_contents($fstab));
        $this->assertTrue($this->messagesContain($messages, 'disabled via PMSS_HARDEN_TMP_TMPFS'), 'expected explicit-false skip log');

        $this->cleanup($dir);
    }

    public function testAddsTmpfsEntryWhenMissing(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-add');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size=2G 0 0', $updated);
        $this->assertTrue($this->messagesContain($messages, 'Added /tmp tmpfs entry'), 'expected add log');

        $this->cleanup($dir);
    }

    public function testSkipsWhenNonTmpfsEntryExists(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-nontmp');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc /tmp ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $this->assertEquals($original, (string)file_get_contents($fstab));
        $this->assertTrue($this->messagesContain($messages, 'non-tmpfs'), 'expected non-tmpfs log');

        $this->cleanup($dir);
    }

    public function testUpdatesTmpfsEntryOptions(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-update');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "tmpfs /tmp tmpfs defaults,exec,suid,dev,size=1G 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "tmpfs /tmp tmpfs rw,exec,suid,dev 0 0\n");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $options = $this->fstabOptionsForMount($fstab, '/tmp');
        $this->assertTrue(in_array('noexec', $options, true), 'expected noexec option');
        $this->assertTrue(in_array('nosuid', $options, true), 'expected nosuid option');
        $this->assertTrue(in_array('nodev', $options, true), 'expected nodev option');
        $this->assertTrue(in_array('size=2G', $options, true), 'expected size=2G');
        $this->assertTrue(!in_array('exec', $options, true), 'expected exec removed');
        $this->assertTrue(!in_array('suid', $options, true), 'expected suid removed');
        $this->assertTrue(!in_array('dev', $options, true), 'expected dev removed');
        $this->assertTrue($this->messagesContain($messages, 'Updated /tmp tmpfs options'), 'expected update log');

        $this->cleanup($dir);
    }

    public function testSizeOverride(): void
    {
        $dir = $this->makeTempDir('pmss-tmpfs-size');
        $fstab = $dir.'/fstab';
        $mounts = $dir.'/mounts';

        $original = "UUID=abc / ext4 defaults 0 0\n";
        file_put_contents($fstab, $original);
        file_put_contents($mounts, "");

        $messages = [];
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        putenv('PMSS_HARDEN_TMP_TMPFS=1');
        putenv('PMSS_TMPFS_TMP_SIZE=512M');
        putenv('PMSS_DRY_RUN=1');
        \pmssConfigureTempTmpfsMount($logger, $fstab, $mounts);

        $updated = (string)file_get_contents($fstab);
        $this->assertStringContainsString('size=512M', $updated);
        $this->assertTrue($this->messagesContain($messages, 'size=512M'), 'expected size override log');

        $this->cleanup($dir);
    }

    private function fstabOptionsForMount(string $fstab, string $mountPoint): array
    {
        $lines = file($fstab, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            $columns = preg_split('/\s+/', $trim);
            if (count($columns) < 4) {
                continue;
            }
            if ($columns[1] !== $mountPoint) {
                continue;
            }
            return array_values(array_filter(explode(',', $columns[3]), 'strlen'));
        }
        return [];
    }

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }

    private function restoreEnv(string $key, $value): void
    {
        if ($value === false || $value === null) {
            putenv($key);
            return;
        }
        putenv($key.'='.$value);
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
