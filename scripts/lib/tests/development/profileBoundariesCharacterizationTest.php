<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock profiling bytes and event order without running commands or publishing reports. */
class ProfileBoundariesCharacterizationTest extends TestCase
{
    private function fixture(string $body): array
    {
        $script = <<<'PHP'
namespace ProfileBoundaryFixture;
function microtime($asFloat) { return ++$GLOBALS['tick'] / 8; }
function logmsg($message) { $GLOBALS['trace'][] = ['logmsg', $message]; }
function logMessage($message) { $GLOBALS['trace'][] = ['message', $message]; }
function pmssLogJson($payload) { $GLOBALS['trace'][] = ['json', $payload]; }
function pmssUpdateStep2HandleClassifiedFailure(...$args) { $GLOBALS['trace'][] = ['failure', $args]; }
function getenv($key) { return $GLOBALS['env'][$key] ?? false; }
function pmssJsonEncodePretty($rows) { $GLOBALS['trace'][] = ['encode', $rows]; return 'encoded'; }
function pmssPathTargetIsSafe(...$args) { $GLOBALS['trace'][] = ['safe', $args]; return true; }
function pmssDirEnsureExists(...$args) { $GLOBALS['trace'][] = ['mkdir', $args]; return true; }
function pmssFileWriteComplete(...$args) { $GLOBALS['trace'][] = ['write', $args]; return true; }
$GLOBALS['tick'] = 0;
$GLOBALS['trace'] = [];
$GLOBALS['PMSS_PROFILE'] = [];
PHP;
        // Also runs against the unsplit layout when characterizing the baseline.
        $modules = [];
        foreach (['profileCallables.php', 'profileSummary.php'] as $module) {
            if (is_file($this->pmssRepoPath('scripts/lib/update/runtime/'.$module))) $modules[] = $module;
        }
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/runtime/profile.php', 'ProfileBoundaryFixture', $modules);
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/runtime/commands.php', 'ProfileBoundaryFixture');
        $script .= 'namespace ProfileBoundaryFixture; '.$body;
        $result = $this->pmssExecInlinePhp($script);
        $this->assertSame(0, $result['rc'], $result['output']);
        $decoded = json_decode($result['output'], true);
        $this->assertTrue(is_array($decoded), $result['output']);
        return $decoded;
    }

    /** Explicit ordered row contract, independent of runtime normalization. */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'description' => 'sample', 'command' => '', 'status' => 'OK', 'rc' => 0,
            'duration' => 0.125, 'dry_run' => false, 'stdout_excerpt' => '', 'stderr_excerpt' => '',
        ], $overrides);
    }

    public function testCaptureRetainsRawFieldsAndStatusLogPrecedesEvent(): void
    {
        $result = $this->fixture(<<<'PHP'
$GLOBALS['PMSS_PROFILE'] = 'invalid prior store';
pmssCommandProfileRecord('sample', 'true', 'ok', 7, 1.234567, true, "raw\nout", "err\t");
pmssLogStatus('warn', 'status sample', 9);
echo json_encode([$GLOBALS['PMSS_PROFILE'], $GLOBALS['trace']]);
PHP
        );
        $first = $this->row(['command' => 'true', 'status' => 'ok', 'rc' => 7, 'duration' => 1.2346,
            'dry_run' => true, 'stdout_excerpt' => "raw\nout", 'stderr_excerpt' => "err\t"]);
        $second = $this->row(['description' => 'status sample', 'status' => 'WARN', 'rc' => 9, 'duration' => 0]);
        $this->assertEquals([[$first, $second], [
            ['json', ['event' => 'step', 'data' => $first]],
            ['message', '[WARN 0.000s rc=9] status sample'],
            ['json', ['event' => 'step', 'data' => $second]],
        ]], $result);
        $this->assertSame(array_keys($first), array_keys($result[0][0]));
    }

    public function testSuccessfulCallablesPreserveEveryReturnValue(): void
    {
        foreach ([null, false, 0, '', [], ['value' => 7]] as $value) {
            $result = $this->fixture('$value = '.var_export($value, true).';'
                .'$returned = pmssRunProfiledCallable("sample", static function () use ($value) { return $value; });'
                .'echo json_encode([$returned, $GLOBALS["trace"]]);');
            $this->assertSame($value, $result[0]);
            $this->assertEquals([
                ['logmsg', '[START] sample :: [callable]'],
                ['message', '[OK 0.125s rc=0] sample'],
                ['json', ['event' => 'step', 'data' => $this->row()]],
            ], $result[1]);
        }
    }

    public function testThrownFailuresAreRecordedBeforePropagationOrClassification(): void
    {
        foreach (['RuntimeException' => 'broken', 'Error' => ''] as $type => $message) {
            foreach (['', 'soft_fail'] as $classification) {
                $result = $this->fixture('$failure = new \\'.$type.'('.var_export($message, true).');'
                    .'try { $returned = pmssRunProfiledCallable("sample", static function () use ($failure) { throw $failure; }, [], '
                    .var_export($classification, true).'); $same = null; }'
                    .'catch (\\Throwable $caught) { $same = $caught === $failure; $returned = null; }'
                    .'echo json_encode([$same, $returned, $GLOBALS["trace"]]);');
                $trace = [
                    ['logmsg', '[START] sample :: [callable]'],
                    ['message', '[ERR 0.125s rc=1] sample'],
                    ['json', ['event' => 'step', 'data' => $this->row(['status' => 'ERR', 'rc' => 1])]],
                ];
                if ($classification !== '') $trace[] = ['failure', ['sample', 'soft_fail', 1, $type.($message === '' ? '' : ': '.$message)]];
                $this->assertEquals([$classification === '' ? true : null, null, $trace], $result);
            }
        }
    }

    public function testBatchKeepsArgumentsFallbacksAndInvocationOrder(): void
    {
        $result = $this->fixture(<<<'PHP'
$calls = [];
$callable = static function (...$args) use (&$calls) { $calls[] = $args; return false; };
pmssRunProfiledCallableBatch([
    ['first', $callable], ['second', $callable, null], ['third', $callable, 'ignored'],
    [4, $callable, ['argument', 7], 'soft_fail'], ['last', $callable, []],
]);
echo json_encode([$calls, array_column($GLOBALS['PMSS_PROFILE'], 'description')]);
PHP
        );
        $this->assertSame([[[], [], [], ['argument', 7], []], ['first', 'second', 'third', '4', 'last']], $result);
    }

    public function testSummaryKeepsRawStoreTopFiveCountsAndPublicationOrder(): void
    {
        $result = $this->fixture(<<<'PHP'
$GLOBALS['env'] = ['PMSS_JSON_LOG' => '/fixture/events.jsonl'];
$rows = [];
foreach (['ok', 'err', 'skip', 'warn', null, 'ok'] as $index => $status) {
    $rows[] = ['description' => 'row '.$index, 'status' => $status, 'duration' => $index + 1];
}
$GLOBALS['PMSS_PROFILE'] = array_merge($rows, ['garbage']);
pmssProfileSummary();
echo json_encode([$GLOBALS['PMSS_PROFILE'], $GLOBALS['trace']]);
PHP
        );
        $raw = []; $normalized = [];
        foreach (['ok', 'err', 'skip', 'warn', null, 'ok'] as $index => $status) {
            $raw[] = ['description' => 'row '.$index, 'status' => $status, 'duration' => $index + 1];
            $normalized[] = $this->row(['description' => 'row '.$index, 'status' => $status === null ? 'OTHER' : strtoupper($status), 'duration' => $index + 1]);
        }
        $raw[] = 'garbage';
        $sorted = array_reverse($normalized);
        $top = array_slice($sorted, 0, 5);
        $trace = [['logmsg', 'Step status summary: 2 OK, 1 ERR, 1 SKIP, 2 other'], ['logmsg', 'Step duration summary (top 5):']];
        foreach ($top as $row) $trace[] = ['logmsg', sprintf('  - %s [%s %.3fs rc=0]', $row['description'], $row['status'], $row['duration'])];
        $trace[] = ['json', ['event' => 'profile_summary', 'steps' => $top, 'status_counts' => ['OK' => 2, 'ERR' => 1, 'SKIP' => 1, 'OTHER' => 2]]];
        $trace[] = ['encode', $sorted];
        $trace[] = ['safe', ['/fixture/events.jsonl.profile.json', false]];
        $trace[] = ['mkdir', ['/fixture', 0755]];
        $trace[] = ['write', ['/fixture/events.jsonl.profile.json', 'encoded']];
        $this->assertEquals([$raw, $trace], $result);
        $this->assertSame(array_keys($sorted[0]), array_keys($result[1][8][1][0]));
    }
}
