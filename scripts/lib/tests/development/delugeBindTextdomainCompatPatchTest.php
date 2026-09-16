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
        [$result, $content] = $this->pmssDelugePatchFixtureApply('util.py', $this->legacyUtilSource(), 'pmssPatchDelugeBindTextdomainCodeset');

        $this->assertTrue($result, 'Expected legacy gettext codeset call to be patched');
        $this->assertEquals($this->patchedUtilSource(), $content);
    }

    public function testPatchReturnsTrueWhenCodesetCallAlreadyGuarded(): void
    {
        $original = $this->patchedUtilSource();
        [$result, $content] = $this->pmssDelugePatchFixtureApply('util.py', $original, 'pmssPatchDelugeBindTextdomainCodeset');

        $this->assertTrue($result, 'Expected already guarded gettext codeset call to be accepted');
        $this->assertEquals($original, $content, 'Already guarded util.py should remain unchanged');
    }

    public function testPatchReturnsFalseWhenCodesetCallMissing(): void
    {
        [$result] = $this->pmssDelugePatchFixtureApply('util.py', "import gettext\n\ndef setup_translations():\n    gettext.textdomain('deluge')\n", 'pmssPatchDelugeBindTextdomainCodeset');

        $this->assertTrue($result === false, 'Expected no-op when codeset call is absent');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $original = $this->legacyUtilSource();
        [$result, $content] = $this->pmssDelugePatchFixtureApply('util.py', $original, 'pmssPatchDelugeBindTextdomainCodeset', true);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->pmssAssertMessagesContain($this->logs, 'Would patch Deluge gettext codeset guard', 'Expected dry-run log message');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $this->pmssAssertDelugePatchSymlinkRefused('util.py', $this->legacyUtilSource(), 'pmssPatchDelugeBindTextdomainCodeset');
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
