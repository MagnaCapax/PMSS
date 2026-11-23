<?php
/** Verify quota refresh username guards and unchanged valid-account I/O without host probes. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaRefreshUsernameSafetyTest extends TestCase
{
    public function testInvalidNamesAreRejectedBeforeAnyAccountOperation(): void
    {
        // Refuse malformed selectors as supplied; trimming or normalizing would hide corruption.
        $users = ['', '-', '--help', '-fixtur1', '0', 'Fixtur01', 'fixtur001',
            '.', '..', '../fixtur01', 'fixtur01/sub', '/fixtur01', 'fixtur01.',
            ' fixtur01', 'fixtur01 ', "fixtur01\n", "fixtur01\r", "fixtur01\t",
            "\0", "\0fixtur01", "fixtur\0".'01', "fixtur01\0", "fixtur01\0suffix",
            "fixtur'1", 'fixtur;1'];
        $script = $this->fixture();
        $script .= '$users = '.var_export($users, true).';';
        $script .= <<<'PHP'
$results = [];
foreach ($users as $user) {
    $GLOBALS['events'] = [];
    $results[] = [pmssQuotaSnapshotRefreshUser($user, $log), $GLOBALS['events']];
}
// Invalid names must not stop the sweep or prevent the next valid account's refresh.
$GLOBALS['events'] = [];
$remaining = pmssQuotaSweepRun(array_merge($users, ['fixtur01']),
    static function ($user) use ($log) { return pmssQuotaSnapshotRefreshUser($user, $log); }, $log);
echo json_encode([$results, $remaining, $GLOBALS['events']]);
PHP;
        // One fixed diagnostic must not echo control bytes or use a malformed account-log path.
        $refusal = ['log', 'Refusing quota refresh: invalid username'];
        $observed = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(array_fill(0, count($users), [true, [$refusal]]), $observed[0]);
        $this->assertSame(0, $observed[1]);
        $this->assertSame(array_merge(array_fill(0, count($users), $refusal),
            $this->validTrace('fixtur01', 0)), $observed[2]);
    }

    public function testValidLegacyNamesKeepCommandsPublicationAndOverQuotaStatus(): void
    {
        // qatst names belong to existing-account validation, independently of create-only gates.
        $script = $this->fixture().<<<'PHP'
$results = [];
foreach (['fixtur01', 'fixtur99', 'qatst001'] as $user) {
    foreach ([0, 1] as $rc) {
        $GLOBALS['rc'] = $rc;
        $GLOBALS['events'] = [];
        $results[] = [pmssQuotaSnapshotRefreshUser($user, $log), $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $expected = [];
        foreach (['fixtur01', 'fixtur99', 'qatst001'] as $user) {
            foreach ([0, 1] as $rc) $expected[] = [true, $this->validTrace($user, $rc)];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    /** Intercept all account I/O while retaining the production username validator. */
    private function fixture(): string
    {
        // The real include supplies validation and pure transforms; namespace shims own all I/O.
        $script = <<<'PHP'
namespace QuotaRefreshUsernameFixture;
function realpath($path) { $GLOBALS['events'][] = ['home', $path]; return $path; }
function pmssReadRegularFileContents($path) { $GLOBALS['events'][] = ['read', $path]; return ''; }
function pmssQuotaReportCapture($command) {
    $GLOBALS['events'][] = ['probe', $command];
    return ['rc' => $GLOBALS['rc'], 'lines' => ['Disk quotas for user fixtur01 (uid 1000):'], 'timed_out' => false];
}
function pmssQuotaSnapshotAggregateReport($user, $report) {
    $GLOBALS['events'][] = ['aggregate', $user]; return $report;
}
function pmssQuotaRootCopyWrite($user, $content) {
    $GLOBALS['events'][] = ['root', $user, $content]; return true;
}
function pmssQuotaSnapshotWrite($path, $content) {
    $GLOBALS['events'][] = ['display', $path, $content]; return true;
}
function pmssUserLifecycleContextLog($domain, $action, $user, $context) {
    $GLOBALS['events'][] = ['event', $domain, $action, $user, $context];
}
function pmssUserLog($user, $message) { $GLOBALS['events'][] = ['userlog', $user, $message]; }
$GLOBALS['rc'] = 0;
$log = static function ($message) { $GLOBALS['events'][] = ['log', $message]; };
PHP;
        return $script.$this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/snapshotRefresh.php',
            'QuotaRefreshUsernameFixture');
    }

    /** Preserve the exact successful trace, including root-before-display publication. */
    private function validTrace(string $user, int $rc): array
    {
        $content = "Disk quotas for user fixtur01 (uid 1000):\n";
        $message = $rc === 1 ? 'Quota refreshed (user over quota)' : 'Quota refreshed';
        return [['home', '/home/'.$user], ['read', '/home/'.$user.'/.quota'],
            ['probe', 'quota -u '.escapeshellarg($user).' -v -s 2>&1'], ['aggregate', $user],
            ['root', $user, $content], ['display', '/home/'.$user.'/.quota', $content],
            ['event', 'quota', 'refresh', $user, ['status' => $rc === 1 ? 'WARN' : 'OK', 'message' => $message]],
            ['userlog', $user, $message]];
    }
}
