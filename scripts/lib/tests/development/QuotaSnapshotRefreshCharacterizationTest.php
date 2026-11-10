<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Source-derived legacy traces: no quota commands, home reads or artifact writes run here. */
class QuotaSnapshotRefreshCharacterizationTest extends TestCase
{
    private const USER = 'fixtur01';
    private const HOME = '/home/fixtur01';
    private const PATH = '/home/fixtur01/.quota';
    private const LINES = ['Disk quotas for user fixtur01 (uid 1000):', '  /dev/example 10 20 30 0 0 0'];
    private const CONTENT = "Disk quotas for user fixtur01 (uid 1000):\n  /dev/example 10K 20K 30K 0 0 0\n";
    private const FALLBACK = "Disk quotas for user fixtur01 (uid 0):\n"
        ."     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n"
        ."       /dev/null      0K      0K      0K               0       0       0\n";

    public function testSnapshotFacadeKeepsItsExistingPublicHelpersAvailable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/quotaSnapshot.php', [
            "require_once __DIR__.'/quota/snapshotText.php';",
            "require_once __DIR__.'/quota/snapshotReport.php';",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/quota/snapshotReport.php', [
            'const PMSS_QUOTA_REPORT_TIMEOUT_SECONDS = 20;',
            'function pmssQuotaReportCapture(', 'function pmssQuotaSweepRun(',
        ]);
    }

    /** Evaluate only the refresh definitions in a namespace whose I/O records calls. */
    private function observe(array $scenario = [], bool $sweep = false): array
    {
        $source = $this->pmssReadRepoFile('scripts/lib/quota/snapshotRefresh.php');
        $source = preg_replace('/^require_once .*;\R/m', '', substr($source, 5));
        $stubs = <<<'PHP'
namespace PMSS\QuotaSnapshotFixture;
function record(array $call) {
    $GLOBALS['trace'][] = $call;
    if (($GLOBALS['scenario']['throw_at'] ?? '') === $call[0]) {
        $class = $GLOBALS['scenario']['throw_class'];
        throw new $class('fixture failure');
    }
}
function realpath($path) { record(['home', $path]); return $GLOBALS['scenario']['home'] ?? $path; }
function pmssReadRegularFileContents($path) { record(['read', $path]); return $GLOBALS['scenario']['previous'] ?? null; }
function is_link($path) { record(['link', $path]); return $GLOBALS['scenario']['link'] ?? false; }
function pmssQuotaReportCapture($command) { record(['probe', $command]); return $GLOBALS['scenario']['report']; }
function pmssQuotaSnapshotNormalizeHumanReadableOutput($content) { return \pmssQuotaSnapshotNormalizeHumanReadableOutput($content); }
function pmssQuotaRootCopyWrite($user, $content) { record(['root', $user, $content]); return $GLOBALS['scenario']['root_write'] ?? true; }
function pmssQuotaSnapshotWrite($path, $content) { record(['display', $path, $content]); return $GLOBALS['scenario']['display_write'] ?? true; }
function pmssUserLifecycleContextLog($domain, $action, $user, $context) { record(['event', $domain, $action, $user, $context]); }
function pmssUserLog($user, $message) { record(['userlog', $user, $message]); }
PHP;
        $scenario += ['report' => ['rc' => 0, 'lines' => self::LINES, 'timed_out' => false]];
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/quotaSnapshot.php'), true).';'
            .'eval('.var_export($stubs.$source, true).');'
            .'$GLOBALS["scenario"] = '.var_export($scenario, true).'; $GLOBALS["trace"] = [];'
            .'$log = static function ($message) { \PMSS\QuotaSnapshotFixture\record(["log", $message]); };'
            .'$refresh = static function ($user) use ($log) { return \PMSS\QuotaSnapshotFixture\pmssQuotaSnapshotRefreshUser($user, $log); };'
            .'$result = null; $error = null; try { $result = '
            .($sweep ? 'pmssQuotaSweepRun(["fixtur01", "fixtur02", "fixtur03"], $refresh, $log)' : '$refresh("fixtur01")')
            .'; } catch (\Throwable $caught) { $error = [get_class($caught), $caught->getMessage()]; }'
            .'echo json_encode([$result, $GLOBALS["trace"], $error]);';
        $run = $this->pmssExecInlinePhpWithTempStderr($script);
        $this->assertSame(0, $run['result']['rc'], (string) @file_get_contents($run['stderrPath']));
        $decoded = json_decode($run['result']['output'], true);
        $this->assertTrue(is_array($decoded), $run['result']['output']);
        return $decoded;
    }

    private function probeTrace(): array
    {
        return [['home', self::HOME], ['read', self::PATH], ['probe', "quota -u 'fixtur01' -v -s 2>&1"]];
    }

    /** Literal context and account-log pairs preserve message bytes and merge order. */
    private function eventTrace(string $action, string $status, string $message, array $context = [], ?string $userMessage = null): array
    {
        return [
            ['event', 'quota', $action, self::USER, ['status' => $status, 'message' => $message] + $context],
            ['userlog', self::USER, $userMessage ?? $message],
        ];
    }

    public function testUnexpectedHomesStopBeforeReadsAndDoNotMirrorToAccountLogs(): void
    {
        foreach ([false, '/home/fixtur02', '/home/fixtur01-extra'] as $home) {
            $this->assertSame([true, [
                ['home', self::HOME],
                ['log', "Refusing quota refresh for fixtur01: unexpected home path '{$home}'"],
                ['event', 'quota', 'invariant_home_prefix', self::USER, [
                    'status' => 'ERR', 'message' => 'Refusing quota refresh due to unexpected home path',
                    'expected_home' => self::HOME, 'real_home' => $home,
                ]],
            ], null], $this->observe(['home' => $home]));
        }
    }

    public function testTimeoutDiscardsPartialOutputAndNeverAttemptsEitherWrite(): void
    {
        foreach ([124, 137] as $rc) {
            $trace = array_merge($this->probeTrace(), [
                ['log', 'quota command for fixtur01 exceeded its 20s deadline and was killed'],
            ], $this->eventTrace('refresh', 'ERR', 'Quota command timed out',
                ['rc' => $rc, 'timeout_seconds' => 20], 'quota refresh timed out after 20s'));
            $this->assertSame([false, $trace, null], $this->observe([
                'report' => ['rc' => $rc, 'lines' => self::LINES, 'timed_out' => true],
                'previous' => 'old snapshot', 'link' => true,
            ]));
        }
    }

    public function testValidReportsKeepWriteOrderFailuresAndOverQuotaStatus(): void
    {
        // Preserve the legacy rc > 1 failure boundary, including a negative rc with valid text.
        foreach ([-1, 0, 1] as $rc) {
            foreach ([true, false] as $rootWrite) {
                foreach ([true, false] as $displayWrite) {
                    $trace = array_merge($this->probeTrace(), [['root', self::USER, self::CONTENT]]);
                    if (!$rootWrite) $trace[] = ['log', 'quota root copy write failed for fixtur01'];
                    $trace[] = ['display', self::PATH, self::CONTENT];
                    if (!$displayWrite) $trace[] = ['log', 'quota snapshot write failed for fixtur01'];
                    $trace = array_merge($trace, $displayWrite
                        ? $this->eventTrace('refresh', $rc === 1 ? 'WARN' : 'OK', $rc === 1 ? 'Quota refreshed (user over quota)' : 'Quota refreshed')
                        : $this->eventTrace('write', 'ERR', 'Quota snapshot write failed'));
                    $this->assertSame([true, $trace, null], $this->observe([
                        'report' => ['rc' => $rc, 'lines' => self::LINES, 'timed_out' => false],
                        'root_write' => $rootWrite, 'display_write' => $displayWrite, 'link' => true,
                    ]));
                }
            }
        }
    }

    public function testFailedReportsPreserveRecognizedSnapshotsAndGuardFallbacks(): void
    {
        foreach ([[2, self::LINES], [0, ['unrecognized output']], [1, []], [137, self::LINES]] as [$rc, $lines]) {
            foreach ([['Disk quotas: previous', false], ['bad old text', true], [null, false], ['', false]] as [$previous, $link]) {
                foreach ([true, false] as $write) {
                    $trace = array_merge($this->probeTrace(), [['log', "quota command failed for fixtur01 (exit {$rc})"]],
                        $this->eventTrace('refresh', 'ERR', 'Quota command failed', ['rc' => $rc], "quota refresh failed (rc={$rc})"));
                    if ($previous !== 'Disk quotas: previous') {
                        $trace[] = ['link', self::PATH];
                        if (!$link) {
                            $trace[] = ['display', self::PATH, self::FALLBACK];
                            $trace[] = ['log', $write ? 'quota fallback snapshot written for fixtur01' : 'quota fallback snapshot write failed for fixtur01'];
                            $trace = array_merge($trace, $write
                                ? $this->eventTrace('fallback', 'WARN', 'Quota fallback snapshot written after refresh failure', [], 'Quota fallback snapshot written')
                                : $this->eventTrace('fallback', 'ERR', 'Quota fallback snapshot write failed'));
                        }
                    }
                    $this->assertSame([true, $trace, null], $this->observe([
                        'report' => ['rc' => $rc, 'lines' => $lines, 'timed_out' => false],
                        'previous' => $previous, 'link' => $link, 'display_write' => $write,
                    ]));
                }
            }
        }
    }

    public function testExceptionsAndErrorsPropagateAtTheirOriginalBoundary(): void
    {
        $trace = array_merge($this->probeTrace(), [['root', self::USER, self::CONTENT], ['display', self::PATH, self::CONTENT]],
            $this->eventTrace('refresh', 'OK', 'Quota refreshed'));
        foreach (['RuntimeException', 'Error'] as $class) {
            foreach ($trace as $index => $call) {
                $this->assertSame([null, array_slice($trace, 0, $index + 1), [$class, 'fixture failure']],
                    $this->observe(['throw_at' => $call[0], 'throw_class' => $class]));
            }
        }
    }

    public function testTimeoutStopsTheIntegratedSweepWithItsOriginalRemainingCount(): void
    {
        $trace = array_merge($this->probeTrace(), [
            ['log', 'quota command for fixtur01 exceeded its 20s deadline and was killed'],
        ], $this->eventTrace('refresh', 'ERR', 'Quota command timed out',
            ['rc' => 124, 'timeout_seconds' => 20], 'quota refresh timed out after 20s'), [
            ['log', 'Quota sweep stopped after a hung quota call for fixtur01; 2 remaining account(s) keep their previous snapshot until the next run'],
        ]);
        $this->assertSame([2, $trace, null], $this->observe([
            'report' => ['rc' => 124, 'lines' => [], 'timed_out' => true],
        ], true));
    }
}
