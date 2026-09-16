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
        [$result, $content] = $this->pmssDelugePatchFixtureApply('core.py', $this->legacyCacheRatioSource(), 'pmssPatchDelugeCacheHitRatio');

        $this->assertTrue($result, 'Expected legacy cache ratio block to be patched');
        $this->assertEquals($this->patchedCacheRatioSource(), $content);
    }

    public function testPatchEnsureVisitsUniqueGlobMatchesInOrder(): void
    {
        $first = $this->tempDir.'/alpha.py';
        $second = $this->tempDir.'/beta.py';
        file_put_contents($first, "alpha\n");
        file_put_contents($second, "beta\n");

        $visited = [];
        $patch = static function (string $path, bool $dryRun, callable $log) use (&$visited, $second): bool {
            $visited[] = [$path, $dryRun];
            $log('visited '.$path);
            return $path === $second;
        };

        $result = \pmssDelugePatchEnsure([
            $this->tempDir.'/*.py',
            $first,
            $this->tempDir.'/missing-*',
        ], $patch, true, $this->logger);

        $this->assertTrue($result, 'Expected dispatcher to report when any unique target patched');
        $this->assertEquals([[$first, true], [$second, true]], $visited, 'Patch candidates should be de-duplicated in glob order');
        $this->assertEquals(['visited '.$first, 'visited '.$second], $this->logs, 'Dispatcher must pass the shared logger through');
    }

    public function testLineSearchHonorsForwardBackwardAndRegexWindows(): void
    {
        $lines = [
            'alpha',
            '    if blocks_read:',
            '        metric = disk.num_blocks_cache_hits',
            '    else:',
        ];

        $this->assertEquals(2, \pmssDelugeLineSearch($lines, 'num_blocks', 0));
        $this->assertEquals(1, \pmssDelugeLineSearch($lines, '/^\\s*if blocks_read:\\s*$/', 3, 0, -1, true));
        $this->assertEquals(null, \pmssDelugeLineSearch($lines, 'else', 0, 2));
        $this->assertEquals(null, \pmssDelugeLineSearch($lines, 'alpha', 0, null, 0));
    }

    public function testPatchReturnsTrueWhenGuardAlreadyPresent(): void
    {
        $original = "class Core:\n    def update_stats(self):\n        if blocks_read:\n            try:\n                self.session_status['read_hit_ratio'] = (\n                    self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n                )\n            except KeyError:\n                self.session_status['read_hit_ratio'] = 0.0\n        else:\n            self.session_status['read_hit_ratio'] = 0.0\n";
        [$result, $content] = $this->pmssDelugePatchFixtureApply('core.py', $original, 'pmssPatchDelugeCacheHitRatio');

        $this->assertTrue($result, 'Expected already-guarded block to be accepted');
        $this->assertEquals($original, $content, 'Already guarded file should remain unchanged');
    }

    public function testPatchDryRunDoesNotModifyFile(): void
    {
        $original = $this->legacyCacheRatioSource();
        [$result, $content] = $this->pmssDelugePatchFixtureApply('core.py', $original, 'pmssPatchDelugeCacheHitRatio', true);

        $this->assertTrue($result, 'Expected dry-run patch to report success');
        $this->assertEquals($original, $content, 'Dry-run must not modify file content');
        $this->pmssAssertMessagesContain($this->logs, 'Would patch Deluge cache hit ratio', 'Expected dry-run log message');
    }

    public function testPatchReturnsFalseWhenCacheRatioLineMissing(): void
    {
        [$result] = $this->pmssDelugePatchFixtureApply('core.py', "class Core:\n    def update_stats(self):\n        self.session_status['other_metric'] = 1\n", 'pmssPatchDelugeCacheHitRatio');

        $this->assertTrue($result === false, 'Expected no-op when cache ratio line is absent');
    }

    public function testPatchLogsWarningWhenElseBlockMissing(): void
    {
        $original = "class Core:\n    def update_stats(self):\n        if blocks_read:\n            self.session_status['read_hit_ratio'] = (\n                self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n            )\n";
        [$result, $content] = $this->pmssDelugePatchFixtureApply('core.py', $original, 'pmssPatchDelugeCacheHitRatio');

        $this->assertTrue($result === false, 'Expected patch to fail without an else block');
        $this->assertEquals($original, $content, 'Failed patch must not modify file content');
        $this->pmssAssertMessagesContain($this->logs, 'Unable to locate Deluge cache ratio else block', 'Expected missing else warning');
    }

    public function testPatchRejectsSymlinkPath(): void
    {
        $this->pmssAssertDelugePatchSymlinkRefused('core.py', $this->legacyCacheRatioSource(), 'pmssPatchDelugeCacheHitRatio');
    }

    private function legacyCacheRatioSource(): string
    {
        return "class Core:\n    def update_stats(self):\n        if blocks_read:\n            self.session_status['read_hit_ratio'] = (\n                self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n            )\n        else:\n            self.session_status['read_hit_ratio'] = 0.0\n";
    }

    private function patchedCacheRatioSource(): string
    {
        return "class Core:\n    def update_stats(self):\n        if blocks_read:\n            try:\n                self.session_status['read_hit_ratio'] = (\n                    self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n                )\n            except KeyError:\n                self.session_status['read_hit_ratio'] = 0.0\n        else:\n            self.session_status['read_hit_ratio'] = 0.0\n";
    }

}
