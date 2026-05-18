<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

class DelugeFindCallerCompatPatchTest extends DelugeAppTestCase
{
    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-findcaller-');
    }

    public function testPatchAddsStacklevelToLegacySignature(): void
    {
        $path = $this->tempDir.'/log.py';
        file_put_contents($path, "class Logging:\n    def findCaller(self, stack_info=False):  # NOQA: N802\n        return ('x', 1, 'y', None)\n");

        $result = \pmssPatchDelugeFindCallerSignature($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected legacy signature to be patched');
        $this->assertStringContainsString('def findCaller(self, stack_info=False, stacklevel=1):', $content);
    }

    public function testPatchReturnsTrueForAlreadyPatchedSignature(): void
    {
        $path = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False, stacklevel=1):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeFindCallerSignature($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected patched signature to be accepted');
        $this->assertEquals($original, $content, 'Already patched file should remain unchanged');
    }

    public function testPatchReturnsFalseWhenSignatureMissing(): void
    {
        $path = $this->tempDir.'/log.py';
        file_put_contents($path, "class Logging:\n    def not_find_caller(self):\n        return None\n");

        $result = \pmssPatchDelugeFindCallerSignature($path, false, $this->logger);

        $this->assertTrue($result === false, 'Expected no-op when signature is absent');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $path = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeFindCallerSignature($path, true, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->assertTrue($this->pmssMessagesContain($this->logs, 'Would patch Deluge findCaller signature'), 'Expected dry-run log message');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $realPath = $this->tempDir.'/log-real.py';
        $linkPath = $this->tempDir.'/log.py';
        $original = "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n";
        file_put_contents($realPath, $original);
        @symlink($realPath, $linkPath);

        $result = \pmssPatchDelugeFindCallerSignature($linkPath, false, $this->logger);
        $content = (string) file_get_contents($realPath);

        $this->assertTrue($result === false, 'Expected symlink path to be refused');
        $this->assertEquals($original, $content, 'Symlink target must remain unchanged');
    }

}
