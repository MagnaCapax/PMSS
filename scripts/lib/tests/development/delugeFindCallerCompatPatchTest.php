<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

putenv('PMSS_DELUGE_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/deluge.php';

class DelugeFindCallerCompatPatchTest extends TestCase
{
    /** @var string */
    private $tempDir;

    /** @var array<int,string> */
    private $logs = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-deluge-findcaller-'.bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0700, true);
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        $this->removePath($this->tempDir);
    }

    public function testPatchAddsStacklevelToLegacySignature(): void
    {
        $path = $this->tempDir.'/log.py';
        file_put_contents($path, "class Logging:\n    def findCaller(self, stack_info=False):  # NOQA: N802\n        return ('x', 1, 'y', None)\n");

        $result = \pmssPatchDelugeFindCallerSignature($path, false, [$this, 'collectLog']);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected legacy signature to be patched');
        $this->assertStringContainsString('def findCaller(self, stack_info=False, stacklevel=1):', $content);
    }

    public function testPatchReturnsTrueForAlreadyPatchedSignature(): void
    {
        $path = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False, stacklevel=1):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeFindCallerSignature($path, false, [$this, 'collectLog']);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected patched signature to be accepted');
        $this->assertEquals($original, $content, 'Already patched file should remain unchanged');
    }

    public function testPatchReturnsFalseWhenSignatureMissing(): void
    {
        $path = $this->tempDir.'/log.py';
        file_put_contents($path, "class Logging:\n    def not_find_caller(self):\n        return None\n");

        $result = \pmssPatchDelugeFindCallerSignature($path, false, [$this, 'collectLog']);

        $this->assertTrue($result === false, 'Expected no-op when signature is absent');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $path = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeFindCallerSignature($path, true, [$this, 'collectLog']);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->assertTrue($this->logContains('Would patch Deluge findCaller signature'), 'Expected dry-run log message');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $realPath = $this->tempDir.'/log-real.py';
        $linkPath = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($realPath, $original);
        @symlink($realPath, $linkPath);

        $result = \pmssPatchDelugeFindCallerSignature($linkPath, false, [$this, 'collectLog']);
        $content = (string) file_get_contents($realPath);

        $this->assertTrue($result === false, 'Expected symlink path to be refused');
        $this->assertEquals($original, $content, 'Symlink target must remain unchanged');
    }

    public function collectLog(string $message): void
    {
        $this->logs[] = $message;
    }

    private function logContains(string $needle): bool
    {
        foreach ($this->logs as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function removePath(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path.'/'.$item);
        }

        @rmdir($path);
    }
}
