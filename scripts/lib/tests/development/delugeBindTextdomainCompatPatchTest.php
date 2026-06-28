<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

class DelugeBindTextdomainCompatPatchTest extends DelugeAppTestCase
{
    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-bindtextdomain-');
    }

    public function testPatchGuardsLegacyCodesetCall(): void
    {
        $path = $this->tempDir.'/util.py';
        file_put_contents($path, $this->legacyUtilSource());

        $result = \pmssPatchDelugeBindTextdomainCodeset($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected legacy gettext codeset call to be patched');
        $this->assertEquals($this->patchedUtilSource(), $content);
    }

    public function testPatchReturnsTrueWhenCodesetCallAlreadyGuarded(): void
    {
        $path = $this->tempDir.'/util.py';
        $original = $this->patchedUtilSource();
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeBindTextdomainCodeset($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected already guarded gettext codeset call to be accepted');
        $this->assertEquals($original, $content, 'Already guarded util.py should remain unchanged');
    }

    public function testPatchReturnsFalseWhenCodesetCallMissing(): void
    {
        $path = $this->tempDir.'/util.py';
        file_put_contents($path, "import gettext\n\ndef setup_translations():\n    gettext.textdomain('deluge')\n");

        $result = \pmssPatchDelugeBindTextdomainCodeset($path, false, $this->logger);

        $this->assertTrue($result === false, 'Expected no-op when codeset call is absent');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $path = $this->tempDir.'/util.py';
        $original = $this->legacyUtilSource();
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeBindTextdomainCodeset($path, true, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->pmssAssertMessagesContain($this->logs, 'Would patch Deluge gettext codeset guard', 'Expected dry-run log message');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $realPath = $this->tempDir.'/util-real.py';
        $linkPath = $this->tempDir.'/util.py';
        $original = $this->legacyUtilSource();
        file_put_contents($realPath, $original);
        @symlink($realPath, $linkPath);

        $result = \pmssPatchDelugeBindTextdomainCodeset($linkPath, false, $this->logger);
        $content = (string) file_get_contents($realPath);

        $this->assertTrue($result === false, 'Expected symlink path to be refused');
        $this->assertEquals($original, $content, 'Symlink target must remain unchanged');
    }

    private function legacyUtilSource(): string
    {
        return "import gettext\n\ndef setup_translations():\n    gettext.bindtextdomain('deluge', translations_path)\n    gettext.bind_textdomain_codeset('deluge', 'UTF-8')\n";
    }

    private function patchedUtilSource(): string
    {
        return "import gettext\n\ndef setup_translations():\n    gettext.bindtextdomain('deluge', translations_path)\n    if hasattr(gettext, 'bind_textdomain_codeset'):\n        gettext.bind_textdomain_codeset('deluge', 'UTF-8')\n";
    }
}
