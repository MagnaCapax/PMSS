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
        [$result, $content] = $this->pmssDelugePatchFixtureApply('log.py', "class Logging:\n    def findCaller(self, stack_info=False):  # NOQA: N802\n        return ('x', 1, 'y', None)\n", 'pmssPatchDelugeFindCallerSignature');

        $this->assertTrue($result, 'Expected legacy signature to be patched');
        $this->assertEquals("class Logging:\n    def findCaller(self, stack_info=False, stacklevel=1):  # NOQA: N802\n        return ('x', 1, 'y', None)\n", $content);
    }

    public function testPatchReturnsTrueForAlreadyPatchedSignature(): void
    {
        $original = "class Logging:\n    def findCaller(self, stack_info=False, stacklevel=1):\n        return ('x', 1, 'y', None)\n";
        [$result, $content] = $this->pmssDelugePatchFixtureApply('log.py', $original, 'pmssPatchDelugeFindCallerSignature');

        $this->assertTrue($result, 'Expected patched signature to be accepted');
        $this->assertEquals($original, $content, 'Already patched file should remain unchanged');
    }

    public function testPatchReturnsFalseWhenSignatureMissing(): void
    {
        [$result] = $this->pmssDelugePatchFixtureApply('log.py', "class Logging:\n    def not_find_caller(self):\n        return None\n", 'pmssPatchDelugeFindCallerSignature');

        $this->assertTrue($result === false, 'Expected no-op when signature is absent');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $original = "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n";
        [$result, $content] = $this->pmssDelugePatchFixtureApply('log.py', $original, 'pmssPatchDelugeFindCallerSignature', true);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->pmssAssertMessagesContain($this->logs, 'Would patch Deluge findCaller signature', 'Expected dry-run log message');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $this->pmssAssertDelugePatchSymlinkRefused('log.py', "class Logging:\n    def findCaller(self, stack_info=False):\n        return ('x', 1, 'y', None)\n", 'pmssPatchDelugeFindCallerSignature');
    }

}
