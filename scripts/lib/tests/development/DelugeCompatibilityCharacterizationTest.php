<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

/**
 * Behavior lock for scripts/lib/update/apps/deluge.php,
 * scripts/lib/update/apps/delugePatches.php, scripts/lib/update/apps/delugeWebBasePatch.php,
 * scripts/lib/update/apps/deluge/patchFile.php and scripts/lib/update/apps/deluge/legacyArtifacts.php.
 * All mutations stay in fixture trees; candidate scans and directory removal are injected.
 */
class DelugeCompatibilityCharacterizationTest extends DelugeAppTestCase
{
    public static $events = [];
    public static $results = [];
    public static $unlinkFailure = '';

    protected function setUp(): void
    {
        $this->pmssSetUpDelugeFixture('pmss-deluge-compatibility-');
        self::$events = self::$results = [];
        self::$unlinkFailure = '';
    }

    /** Original input, exact replacement, and the patch-specific dry-run label. */
    private function patches(): array
    {
        return [
            'pmssPatchDelugeCacheHitRatio' => [
                "if blocks_read:\n    self.session_status['read_hit_ratio'] = (\n        self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n    )\nelse:\n    self.session_status['read_hit_ratio'] = 0.0",
                "if blocks_read:\n    try:\n        self.session_status['read_hit_ratio'] = (\n            self.session_status['disk.num_blocks_cache_hits'] / blocks_read\n        )\n    except KeyError:\n        self.session_status['read_hit_ratio'] = 0.0\nelse:\n    self.session_status['read_hit_ratio'] = 0.0\n",
                'cache hit ratio',
            ],
            'pmssPatchDelugeFindCallerSignature' => [
                "class Logging:\r\n    def findCaller(self, stack_info=False):  # compat\r\n        pass",
                "class Logging:\n    def findCaller(self, stack_info=False, stacklevel=1):  # compat\n        pass\n",
                'findCaller signature',
            ],
            'pmssPatchDelugeBindTextdomainCodeset' => [
                "def setup():\n    gettext.bind_textdomain_codeset('deluge', 'UTF-8')",
                "def setup():\n    if hasattr(gettext, 'bind_textdomain_codeset'):\n        gettext.bind_textdomain_codeset('deluge', 'UTF-8')\n",
                'gettext codeset guard',
            ],
            'pmssPatchDelugeWebBasePathBytes' => [
                "self.top_level.putChild(self.base.strip('/'), self.top_level)",
                "self.top_level.putChild(self.base.strip('/').encode(), self.top_level)\n",
                'WebUI base path bytes',
            ],
        ];
    }

    public function testExactPatchBytesDryRunMessagesAndIdempotence(): void
    {
        foreach ($this->patches() as $patch => [$source, $expected, $label]) {
            foreach ([true, false] as $dryRun) {
                $path = $this->pmssWriteFile($this->tempDir.'/'.$patch.'.py', $source);
                touch($path, 1000000000);
                $this->logs = [];
                $this->assertTrue($patch($path, $dryRun, $this->logger), $patch);
                $this->assertSame($dryRun ? $source : $expected, file_get_contents($path), $patch);
                $this->assertSame($dryRun ? ['[DRYRUN] Would patch Deluge '.$label.' in '.$path] : [], $this->logs);
                if ($dryRun) {
                    clearstatcache(true, $path);
                    $this->assertSame(1000000000, filemtime($path));
                }
            }
            // Accepted already-patched input must stay byte-for-byte intact, even without a final newline.
            $path = $this->pmssWriteFile($path, rtrim($expected, "\n"));
            touch($path, 1000000000);
            foreach ([false, true] as $dryRun) {
                $this->logs = [];
                $this->assertTrue($patch($path, $dryRun, $this->logger), $patch);
                clearstatcache(true, $path);
                $this->assertSame(rtrim($expected, "\n"), file_get_contents($path));
                $this->assertSame(1000000000, filemtime($path));
                $this->assertSame([], $this->logs);
            }
        }
    }

    public function testUnsupportedAndMalformedSourcesKeepBytesAndDiagnostics(): void
    {
        foreach ($this->patches() as $patch => $unused) {
            foreach (['', "# unrelated\npass", "\r\n"] as $source) {
                $this->logs = [];
                [$result, $content] = $this->pmssDelugePatchFixtureApply('unsupported.py', $source, $patch);
                $this->assertFalse($result, $patch);
                $this->assertSame($source, $content);
                $this->assertSame([], $this->logs);
            }
        }
        foreach ([
            ['pmssPatchDelugeCacheHitRatio', "disk.num_blocks_cache_hits\n", 'cache ratio block'],
            ['pmssPatchDelugeCacheHitRatio', "if blocks_read:\n    disk.num_blocks_cache_hits\n", 'cache ratio else block'],
            ['pmssPatchDelugeBindTextdomainCodeset', "# gettext.bind_textdomain_codeset('deluge')\n", 'bind_textdomain_codeset call'],
        ] as [$patch, $source, $label]) {
            $this->logs = [];
            [$result, $content] = $this->pmssDelugePatchFixtureApply('malformed.py', $source, $patch);
            $this->assertFalse($result);
            $this->assertSame($source, $content);
            $this->assertSame(['[WARN] Unable to locate Deluge '.$label.' in '.$this->tempDir.'/malformed.py'], $this->logs);
        }
    }

    public function testMissingDirectoriesAndLinksAreRefusedWithoutLogging(): void
    {
        foreach ($this->patches() as $patch => [$source]) {
            $target = $this->pmssWriteFile($this->tempDir.'/'.$patch.'.py', $source);
            $link = $target.'.link';
            $this->assertTrue(symlink($target, $link));
            $dangling = $target.'.dangling';
            $this->assertTrue(symlink($target.'.missing', $dangling));
            foreach ([$link, $dangling, $target.'.missing', $this->tempDir] as $path) {
                $this->logs = [];
                $this->assertFalse($patch($path, false, $this->logger), $patch);
                $this->assertSame([], $this->logs);
                $this->assertSame($source, file_get_contents($target));
            }
        }
    }

    public function testDryRunLoggerFaultPreservesOriginalThrowableAndFile(): void
    {
        foreach ($this->patches() as $patch => [$source]) {
            foreach ([new \RuntimeException('fixture log fault'), new \Error('fixture log fault')] as $fault) {
                $path = $this->pmssWriteFile($this->tempDir.'/fault.py', $source);
                $caught = null;
                try {
                    $patch($path, true, static function () use ($fault): void { throw $fault; });
                } catch (\Throwable $exception) {
                    $caught = $exception;
                }
                $this->assertSame($fault, $caught);
                $this->assertSame($source, file_get_contents($path));
            }
        }
    }

    /** Capture the real catalog without probing or patching any host installation. */
    public static function recordCandidates(array $patterns, callable $patch, bool $dryRun, callable $log): bool
    {
        self::$events[] = [$patterns, $patch, $dryRun];
        $log($patch);
        return array_shift(self::$results);
    }

    public function testCandidateAndStatusOrderingMatchesTheOriginalCatalog(): void
    {
        $this->exportFunction('pmssDelugePatchAll', ['pmssDelugePatchEnsure(' => '\\PMSS\\Tests\\DelugeCompatibilityCharacterizationTest::recordCandidates(']);
        $root = $this->tempDir.'/store';
        $this->pmssWithEnv(['PMSS_TORRENT_CLIENTS_STORE_ROOT' => $root], function () use ($root): void {
            self::$results = [true, false, true, false];
            ob_start();
            try {
                \PmssDelugeCompatibilityFixture\pmssDelugePatchAll(true, $this->logger);
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $expected = [];
            foreach (['core/core.py', 'log.py', 'i18n/util.py', 'ui/web/server.py'] as $index => $file) {
                $patterns = ['/usr/lib/python3/dist-packages/deluge/'.$file,
                    '/usr/lib/python3*/dist-packages/deluge/'.$file,
                    '/usr/local/lib/python3*/dist-packages/deluge/'.$file];
                if ($index === 3) $patterns[] = $root.'/deluge/*/lib/python3*/site-packages/deluge/ui/web/server.py';
                $expected[] = [$patterns, array_keys($this->patches())[$index], true];
            }
            $this->assertSame($expected, self::$events);
            $this->assertSame(array_keys($this->patches()), $this->logs);
            $this->assertSame("\t*** Deluge cache ratio guard ensured\n\t*** Deluge gettext Python 3.11 compatibility ensured\n", $output);
        });
    }

    /** Fake only the command boundary: no directory is removed by a shell. */
    public static function removeDirectory(string $label, string $command): int
    {
        self::$events[] = ['command', $label, $command];
        return array_shift(self::$results);
    }

    public static function unlinkArtifact(string $path): bool
    {
        self::$events[] = ['unlink', $path];
        return $path !== self::$unlinkFailure && unlink($path);
    }

    public function testLegacyCleanupCountsOnlySuccessfulOperationsInInputOrder(): void
    {
        $this->exportFunction('pmssRemoveLegacyDelugePython2Artifacts', [
            'runStep(' => '\\PMSS\\Tests\\DelugeCompatibilityCharacterizationTest::removeDirectory(',
            '@unlink(' => '\\PMSS\\Tests\\DelugeCompatibilityCharacterizationTest::unlinkArtifact(',
        ]);
        foreach ([false, true] as $dryRun) {
            $root = $this->tempDir.'/'.($dryRun ? 'dry' : 'live');
            $file = $this->pmssWriteFile($root.'/deluge-file.egg', 'legacy');
            $failedFile = $this->pmssWriteFile($root.'/deluge-unlink.egg-info', 'legacy');
            $firstDir = $root.'/deluge-first.egg';
            $lastDir = $root.'/deluge-last.egg-info';
            $this->pmssEnsureDir($firstDir);
            $this->pmssEnsureDir($lastDir);
            $outside = $this->pmssWriteFile($root.'/other.egg', 'keep');
            self::$unlinkFailure = $failedFile;
            self::$events = [];
            self::$results = [1, 0];
            $log = static function (string $message): void { self::$events[] = ['log', $message]; };
            $count = \PmssDelugeCompatibilityFixture\pmssRemoveLegacyDelugePython2Artifacts(
                $dryRun, $log, $root, [$file, $firstDir, $outside, $failedFile, $lastDir]);
            $this->assertSame($dryRun ? 4 : 2, $count);
            $refusal = ['log', '[WARN] Refusing unsafe Deluge legacy artifact cleanup candidate: '.$outside];
            $this->assertSame($dryRun ? [
                ['log', '[DRYRUN] Would remove legacy Deluge Python 2 artifact: '.$file],
                ['log', '[DRYRUN] Would remove legacy Deluge Python 2 artifact: '.$firstDir], $refusal,
                ['log', '[DRYRUN] Would remove legacy Deluge Python 2 artifact: '.$failedFile],
                ['log', '[DRYRUN] Would remove legacy Deluge Python 2 artifact: '.$lastDir],
            ] : [
                ['unlink', $file], ['log', 'Removed legacy Deluge Python 2 artifact: '.$file],
                ['command', 'Removing legacy Deluge Python 2 artifact', 'rm -rf '.escapeshellarg($firstDir)], $refusal,
                ['unlink', $failedFile], ['log', '[WARN] Failed to remove legacy Deluge Python 2 artifact: '.$failedFile],
                ['command', 'Removing legacy Deluge Python 2 artifact', 'rm -rf '.escapeshellarg($lastDir)],
            ], self::$events);
            $this->assertSame($dryRun, is_file($file));
            $this->assertSame('legacy', file_get_contents($failedFile));
            $this->assertSame('keep', file_get_contents($outside));
        }
    }

    /** Reflect the original public function so this lock also runs against the baseline. */
    private function exportFunction(string $name, array $replacements): void
    {
        if (!function_exists('PmssDelugeCompatibilityFixture\\'.$name)) {
            eval(\pmssTestingFunctionExport($name, 'PmssDelugeCompatibilityFixture', $replacements));
        }
    }
}
