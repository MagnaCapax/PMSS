<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

class DelugeCacheHitRatioPatchTest extends DelugeAppTestCase
{
    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-cache-hit-ratio-');
    }

    public function testPatchAddsKeyErrorGuardToLegacyBlock(): void
    {
        $path = $this->tempDir.'/core.py';
        file_put_contents($path, $this->legacyCacheRatioSource());

        $result = \pmssPatchDelugeCacheHitRatio($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected legacy cache ratio block to be patched');
        $this->assertStringContainsString('try:', $content);
        $this->assertStringContainsString('except KeyError:', $content);
        $this->assertStringContainsString("self.session_status['disk.num_blocks_cache_hits'] / blocks_read", $content);
    }

    public function testPatchReturnsTrueWhenGuardAlreadyPresent(): void
    {
        $path = $this->tempDir.'/core.py';
        $original = "class Core:\n    def update_stats(self):\n        if blocks_read:\n            try:\n                self.session_status['read_hit_ratio'] = (\n                    self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n                )\n            except KeyError:\n                self.session_status['read_hit_ratio'] = 0.0\n        else:\n            self.session_status['read_hit_ratio'] = 0.0\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeCacheHitRatio($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected already-guarded block to be accepted');
        $this->assertEquals($original, $content, 'Already guarded file should remain unchanged');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $path = $this->tempDir.'/core.py';
        $original = $this->legacyCacheRatioSource();
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeCacheHitRatio($path, true, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->assertTrue($this->pmssMessagesContain($this->logs, 'Would patch Deluge cache hit ratio'), 'Expected dry-run log message');
    }

    public function testPatchReturnsFalseWhenCacheRatioLineMissing(): void
    {
        $path = $this->tempDir.'/core.py';
        file_put_contents($path, "class Core:\n    def update_stats(self):\n        self.session_status['other_metric'] = 1\n");

        $result = \pmssPatchDelugeCacheHitRatio($path, false, $this->logger);

        $this->assertTrue($result === false, 'Expected no-op when cache ratio line is absent');
    }

    public function testPatchLogsWarningWhenElseBlockMissing(): void
    {
        $path = $this->tempDir.'/core.py';
        $original = "class Core:\n    def update_stats(self):\n        if blocks_read:\n            self.session_status['read_hit_ratio'] = (\n                self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n            )\n";
        file_put_contents($path, $original);

        $result = \pmssPatchDelugeCacheHitRatio($path, false, $this->logger);
        $content = (string) file_get_contents($path);

        $this->assertTrue($result === false, 'Expected patch to fail without an else block');
        $this->assertEquals($original, $content, 'Failed patch must not modify file content');
        $this->assertTrue($this->pmssMessagesContain($this->logs, 'Unable to locate Deluge cache ratio else block'), 'Expected missing else warning');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $realPath = $this->tempDir.'/core-real.py';
        $linkPath = $this->tempDir.'/core.py';
        $original = $this->legacyCacheRatioSource();
        file_put_contents($realPath, $original);
        @symlink($realPath, $linkPath);

        $result = \pmssPatchDelugeCacheHitRatio($linkPath, false, $this->logger);
        $content = (string) file_get_contents($realPath);

        $this->assertTrue($result === false, 'Expected symlink path to be refused');
        $this->assertEquals($original, $content, 'Symlink target must remain unchanged');
    }

    private function legacyCacheRatioSource(): string
    {
        return "class Core:\n    def update_stats(self):\n        if blocks_read:\n            self.session_status['read_hit_ratio'] = (\n                self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n            )\n        else:\n            self.session_status['read_hit_ratio'] = 0.0\n";
    }

}
