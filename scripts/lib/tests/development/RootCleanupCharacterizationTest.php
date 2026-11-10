<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/safeRootCleanup.php';

class RootCleanupCharacterizationTest extends TestCase
{
    /** Default discovery keeps its estimates and never reaches deletion or auditing. */
    public function testDefaultDryRunAndQuietOutput(): void
    {
        $result = $this->cleanupRun([]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame("DRY-RUN — root use 95% (500B free). Freeable estimate:\n"
            .$this->byteTable(['apt_cache' => '1.0K', 'journald' => '100.0M', 'rotated_logs' => '20B',
                'atop_logs' => '30B', 'distupgrade_tmp' => '50B', 'TOTAL' => '100.0M'])
            ."Run with --apply to reclaim (whitelist-only).\n", $result['output']);
        $this->assertSame($this->discoveryTrace(), $result['trace']);
        $quiet = $this->cleanupRun(['--quiet']);
        $this->pmssAssertArraySubsetSame(['output' => '', 'trace' => $result['trace']], $quiet);
    }

    /** Apply preserves command bytes/order, audit key order, measured totals and human output. */
    public function testApplyTraceAndAuditPayload(): void
    {
        $result = $this->cleanupRun(['--apply']);
        $this->assertSame(0, $result['rc']);
        $freed = ['apt_cache' => 1024, 'journald' => 104857600, 'rotated_logs' => 20,
            'atop_logs' => 30, 'distupgrade_tmp' => 50];
        $this->assertSame("Reclaimed 100.0M — root 95% -> 80%.\n"
            .$this->byteTable(['apt_cache' => '1.0K', 'journald' => '100.0M', 'rotated_logs' => '20B',
                'atop_logs' => '30B', 'distupgrade_tmp' => '50B']), $result['output']);
        $entry = ['ts' => '2026-01-01T00:00:00+00:00', 'tool' => 'safeRootCleanup', 'host' => 'fixture.example',
            'root_use_pct_before' => 95, 'root_use_pct_after' => 80, 'freed_bytes' => $freed,
            'freed_total' => array_sum($freed), 'rotated_files_removed' => 1, 'atop_files_removed' => 1,
            'log_days' => 3, 'journal_mb' => 200];
        $this->assertSame(array_merge($this->discoveryTrace(), [
            ['command', "du -sb '/var/cache/apt/archives' 2>/dev/null"],
            ['command', 'apt-get clean 2>/dev/null'],
            ['command', "du -sb '/var/cache/apt/archives' 2>/dev/null"],
            ['command', "du -sb '/var/log/journal' 2>/dev/null"],
            ['command', "journalctl --vacuum-size='200M' 2>/dev/null"],
            ['command', "du -sb '/var/log/journal' 2>/dev/null"],
            ['unlink', '/var/log/syslog.1'], ['unlink', '/var/log/atop/atop_20260101'],
            ['command', "rm -rf '/var/tmp/mkinitramfs_fixture' 2>/dev/null"],
            ['exists', '/var/tmp/mkinitramfs_fixture'],
            ['audit-directory', '/var/log/pmss', 0755],
            ['audit-path', '/var/log/pmss/safe-root-cleanup.jsonl'],
            ['json', '/var/log/pmss/safe-root-cleanup.jsonl', $entry],
        ]), $result['trace']);
        $quiet = $this->cleanupRun(['--apply', '--quiet']);
        $this->pmssAssertArraySubsetSame(['output' => '', 'trace' => $result['trace']], $quiet);
    }

    /** A low-use refusal happens before scans; force and unknown capacity retain their gates. */
    public function testRootUseGateAndForce(): void
    {
        foreach ([[], ['--quiet']] as $args) {
            $result = $this->cleanupRun(array_merge(['--apply'], $args), ['free' => 5000]);
            $this->pmssAssertArraySubsetSame([
                'trace' => [], 'output' => $args === [] ? "Root use 50% < --min-pct 90%: nothing to do (use --force to override).\n" : '',
            ], $result);
        }
        $forced = $this->cleanupRun(['--apply', '--force', '--quiet'], ['free' => 5000]);
        $this->assertSame(50, $this->jsonEntry($forced)['root_use_pct_before']);
        $unknown = $this->cleanupRun(['--apply'], ['total' => 0]);
        $this->pmssAssertArraySubsetSame(['trace' => [], 'output' => "Root use 0% < --min-pct 90%: nothing to do (use --force to override).\n"], $unknown);
    }

    /** Failed removals and post-scan redirects earn no bytes; candidate counts remain legacy counts. */
    public function testRemovalFailuresAndChangedPaths(): void
    {
        $paths = ['/var/log/syslog.1', '/var/log/atop/atop_20260101', '/var/tmp/mkinitramfs_fixture'];
        foreach ([
            ['unlinkFailures' => array_slice($paths, 0, 2), 'scratchRemains' => true],
            ['redirectAfterScan' => array_fill_keys($paths, '/home/fixtur01/data')],
            ['redirectAfterScan' => array_fill_keys($paths, false)],
        ] as $options) {
            $result = $this->cleanupRun(['--apply', '--quiet'], $options);
            $entry = $this->jsonEntry($result);
            $this->assertSame(['apt_cache' => 1024, 'journald' => 104857600, 'rotated_logs' => 0,
                'atop_logs' => 0, 'distupgrade_tmp' => 0], $entry['freed_bytes']);
            $this->pmssAssertArraySubsetSame(['rotated_files_removed' => 1, 'atop_files_removed' => 1], $entry);
            if (isset($options['redirectAfterScan'])) {
                $this->assertSame([], array_values(array_filter($result['trace'], static function ($event): bool {
                    return $event[0] === 'unlink' || ($event[0] === 'command' && strpos($event[1], 'rm -rf ') === 0);
                })));
            }
        }
        $growth = $this->jsonEntry($this->cleanupRun(['--apply', '--quiet'], ['aptAfter' => 2048, 'journalAfter' => 419430400]));
        $this->pmssAssertArraySubsetSame(['apt_cache' => 0, 'journald' => 0], $growth['freed_bytes']);
    }

    /** Audit guards short-circuit only JSONL; the guard that refused is the last audit step recorded. */
    public function testAuditFailuresAndOptionMinimums(): void
    {
        foreach (['auditDirectoryFailure', 'auditUnsafe', 'auditWriteFailure'] as $failure) {
            $result = $this->cleanupRun(['--apply', '--quiet'], [$failure => true]);
            $kinds = array_column($result['trace'], 0);
            $this->assertSame(['auditDirectoryFailure' => 'audit-directory', 'auditUnsafe' => 'audit-path',
                'auditWriteFailure' => 'json'][$failure], end($kinds));
            $this->assertSame($failure === 'auditWriteFailure', in_array('json', $kinds, true));
            $this->assertSame($failure !== 'auditDirectoryFailure', in_array('audit-path', $kinds, true));
        }
        $result = $this->cleanupRun(['--apply', '--quiet', '--log-days=0', '--journal-mb=0', '--json=/fixture/audit.jsonl']);
        $entry = $this->jsonEntry($result);
        $this->pmssAssertArraySubsetSame(['log_days' => 1, 'journal_mb' => 50], $entry);
        $this->assertTrue(in_array(['command', "journalctl --vacuum-size='50M' 2>/dev/null"], $result['trace'], true));
        $this->assertTrue(in_array(['audit-path', '/fixture/audit.jsonl'], $result['trace'], true));
    }

    /** Empty glob scans, a missing log root and fresh atop samples keep their discovery results. */
    public function testDiscoveryFallbacks(): void
    {
        foreach ([['empty' => true], ['missingLogs' => true, 'mtime' => 1000000, 'missingFiles' => true],
            ['redirects' => array_fill_keys(['/var/log/syslog.1', '/var/log/atop/atop_20260101',
                '/var/tmp/mkinitramfs_fixture'], '/home/fixtur01/data')],
        ] as $options) {
            $entry = $this->jsonEntry($this->cleanupRun(['--apply', '--quiet'], $options));
            $this->pmssAssertArraySubsetSame(['rotated_files_removed' => 0, 'atop_files_removed' => 0], $entry);
        }
        foreach ([740800 => 1, 740801 => 0] as $mtime => $count) {
            $entry = $this->jsonEntry($this->cleanupRun(['--apply', '--quiet'], ['mtime' => $mtime, 'rotatedMtime' => $mtime]));
            $this->pmssAssertArraySubsetSame(['rotated_files_removed' => $count, 'atop_files_removed' => $count], $entry);
        }
    }

    /** Preserve unit boundaries, rounding and the legacy signed-byte representation. */
    public function testHumanByteBoundaries(): void
    {
        foreach ([0 => '0B', -1 => '-1B', 1023 => '1023B', 1024 => '1.0K',
            1048575 => '1024.0K', 1048576 => '1.0M',
        ] as $bytes => $expected) {
            $this->assertSame($expected, pmssSafeRootCleanupHuman($bytes));
        }
    }

    private function byteTable(array $values): string
    {
        $text = '';
        foreach ($values as $name => $value) $text .= sprintf("  %-16s %10s\n", $name, $value);
        return $text;
    }

    private function jsonEntry(array $result): array
    {
        foreach ($result['trace'] as $event) if ($event[0] === 'json') return $event[2];
        throw new \RuntimeException('Expected cleanup audit was not emitted');
    }

    private function discoveryTrace(): array
    {
        return [['scan', '/var/log', \FilesystemIterator::SKIP_DOTS],
            ['walk', \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD],
            ['glob', '/var/log/atop/atop_*'], ['glob', '/var/tmp/mkinitramfs_*'],
            ['command', "du -sb '/var/tmp/mkinitramfs_fixture' 2>/dev/null"],
            ['command', "du -sb '/var/cache/apt/archives' 2>/dev/null"],
            ['command', "du -sb '/var/log/journal' 2>/dev/null"]];
    }

    /** Reflect the actual helpers into an isolated process with every host operation stubbed. */
    private function cleanupRun(array $args, array $options = []): array
    {
        $script = 'namespace RootCleanupCharacterization; require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/rootCleanupCharacterization.php'), true).';';
        $script .= 'require '.var_export($this->pmssRepoPath('scripts/lib/cli/optionParser.php'), true).';';
        foreach (get_defined_functions()['user'] as $name) {
            if (strpos($name, 'pmsssaferootcleanup') !== 0) continue;
            $script .= \pmssTestingFunctionExport($name, 'RootCleanupCharacterization');
        }
        $script .= '$GLOBALS["cleanupOptions"] = '.var_export($options, true).';'
            .'$GLOBALS["cleanupTrace"] = []; $GLOBALS["cleanupApplying"] = false;'
            .'$GLOBALS["cleanupFiles"] = ["/var/log/syslog.1" => 20, "/var/log/atop/atop_20260101" => 30];'
            .'$GLOBALS["cleanupSizes"] = ["apt" => 1024, "journal" => 314572800];'
            .'ob_start(); $rc = pmssSafeRootCleanupMain('.var_export(array_merge(['safeRootCleanup.php'], $args), true).');'
            .'$output = ob_get_clean(); echo json_encode(["rc" => $rc, "output" => $output, "trace" => $GLOBALS["cleanupTrace"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
