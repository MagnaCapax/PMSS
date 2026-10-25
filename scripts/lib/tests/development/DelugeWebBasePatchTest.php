<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

final class DelugeWebBasePatchTest extends DelugeAppTestCase
{
    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-web-base-');
    }

    private function legacyServer(): string
    {
        return "class DelugeWeb:\n    def setup(self):\n        if self.base != '/':\n"
            ."            self.top_level.putChild(self.base.strip('/'), self.top_level)\n";
    }

    public function testPatchRewritesLegacyCallAndSecondRunDoesNotWrite(): void
    {
        [$result, $content] = $this->pmssDelugePatchFixtureApply('server.py', $this->legacyServer(),
            'pmssPatchDelugeWebBasePathBytes');
        $expected = "class DelugeWeb:\n    def setup(self):\n        if self.base != '/':\n"
            ."            self.top_level.putChild(self.base.strip('/').encode(), self.top_level)\n";
        $this->assertTrue($result);
        $this->assertSame($expected, $content);

        $path = $this->tempDir.'/server.py';
        touch($path, 1000000000);
        $this->assertTrue(\pmssPatchDelugeWebBasePathBytes($path, false, $this->logger));
        clearstatcache(true, $path);
        $this->assertSame($expected, file_get_contents($path));
        $this->assertSame(1000000000, filemtime($path));
    }

    public function testModernServerWithoutLegacyCallIsUntouched(): void
    {
        // No final newline: even newline normalization would be an unwanted write.
        $source = "class DelugeWeb:\n    def setup(self):\n        self.site = Site(self.top_level)";
        [$result, $content] = $this->pmssDelugePatchFixtureApply('server.py', $source,
            'pmssPatchDelugeWebBasePathBytes');
        $this->assertFalse($result);
        $this->assertSame($source, $content);
        $this->assertSame([], $this->logs);
    }

    public function testDryRunReportsPatchWithoutWriting(): void
    {
        [$result, $content] = $this->pmssDelugePatchFixtureApply('server.py', $this->legacyServer(),
            'pmssPatchDelugeWebBasePathBytes', true);
        $this->assertTrue($result);
        $this->assertSame($this->legacyServer(), $content);
        $this->pmssAssertMessagesContain($this->logs, 'Would patch Deluge WebUI base path bytes',
            'Dry-run should describe the planned repair');
    }

    public function testSymlinkIsRefusedWithoutChangingTarget(): void
    {
        $this->pmssAssertDelugePatchSymlinkRefused('server.py', $this->legacyServer(),
            'pmssPatchDelugeWebBasePathBytes');
    }

    public function testMissingServerIsNotCreated(): void
    {
        $path = $this->tempDir.'/missing.py';
        $this->assertFalse(\pmssPatchDelugeWebBasePathBytes($path, false, $this->logger));
        $this->assertFalse(file_exists($path));
    }
}
