<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Sendmail lifetime faults use memory streams without starting a mail process. */
class SupportSendmailCleanupSafetyTest extends TestCase
{
    public function testPipeValidationAndCloseFailuresStillReapTheProcess(): void
    {
        $script = <<<'PHP'
namespace SupportSendmailCleanupFixture;
class RuntimeException extends \RuntimeException {}
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
function proc_open($command, $spec, &$pipes) {
    $GLOBALS['launch'] = [$command, $spec];
    observe('open');
    if ($GLOBALS['case'] === 'open-false') return false;
    $pipes = [];
    foreach (['stdin', 'stdout', 'stderr'] as $index => $name) {
        if ($GLOBALS['case'] === 'missing-'.$index) continue;
        if ($GLOBALS['case'] === 'invalid-'.$index) { $pipes[$index] = false; continue; }
        $pipes[$index] = $handle = \fopen('php://memory', 'w+');
        $GLOBALS['handles'][] = $handle;
        $GLOBALS['names'][(int) $handle] = $name;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function pmssStreamHandleIsOpen($handle) {
    $phase = 'validate-'.(++$GLOBALS['checks']);
    if (isset($GLOBALS['faults'][$phase])) observe($phase);
    return is_resource($handle) && get_resource_type($handle) === 'stream';
}
function pmssSupportStreamWriteAll($stream, $bytes, $context) {
    $GLOBALS['write'] = [$bytes, $context];
    observe('write');
}
function stream_get_contents($stream) { observe('read'); return " diagnostic \n"; }
function fclose($handle) {
    $name = $GLOBALS['names'][(int) $handle];
    \fclose($handle);
    observe('close '.$name);
}
function proc_close($process) {
    \fclose($process);
    observe('reap');
    return $GLOBALS['case'] === 'exit' ? 9 : 0;
}
PHP;
        // Exercise the real pipe cleanup traversal alongside the transport.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandPipes.php', 'SupportSendmailCleanupFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/envelope.php', 'SupportSendmailCleanupFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/sendmail.php', 'SupportSendmailCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_SENDMAIL_CLEANUP'), true) as [$case, $phase, $type, $reapType]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['handles'] = $GLOBALS['names'] = $GLOBALS['faults'] = [];
    $GLOBALS['process'] = $GLOBALS['launch'] = $GLOBALS['write'] = null;
    $GLOBALS['checks'] = 0;
    if ($phase !== '') $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    if ($reapType !== '') $GLOBALS['faults']['reap'] = new $reapType('reap failure');
    $caught = null;
    try {
        pmssSupportMailSendViaSendmail('/fixture/send mail',
            ['from' => 'sender@example.invalid', 'to' => 'support@example.invalid', 'data' => "message\n"]);
    } catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['process']);
    foreach ($GLOBALS['handles'] as $handle) $live = $live || is_resource($handle);
    $expectedFault = $GLOBALS['faults']['reap'] ?? $GLOBALS['faults'][$phase] ?? null;
    $rows[] = [$caught === null ? null : $caught->getMessage(),
        $expectedFault === null || $caught === $expectedFault, $live,
        $GLOBALS['events'], $GLOBALS['launch'], $GLOBALS['write']];
    // Clean a regressed implementation's fake resources after recording the leak.
    foreach ($GLOBALS['handles'] as $handle) if (is_resource($handle)) \fclose($handle);
    if (is_resource($GLOBALS['process'])) \fclose($GLOBALS['process']);
}
echo json_encode($rows);
PHP;
        $cases = [['ok', '', '', ''], ['exit', '', '', ''], ['open-false', '', '', '']];
        foreach (range(0, 2) as $index) {
            foreach (['missing', 'invalid'] as $kind) $cases[] = [$kind.'-'.$index, '', '', ''];
        }
        // Exceptions and errors at acquisition, use, cleanup, and combined close/reap failures.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach (['open', 'validate-1', 'validate-2', 'validate-3', 'write', 'read',
                'close stdin', 'close stdout', 'close stderr', 'reap'] as $phase) {
                $cases[] = ['ok', $phase, $type, ''];
            }
            $cases[] = ['missing-2', 'close stdin', $type, ''];
            foreach (['RuntimeException', 'Error'] as $reapType) {
                $cases[] = ['ok', 'close stdout', $type, $reapType];
                $cases[] = ['missing-2', 'close stdin', $type, $reapType];
            }
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_SENDMAIL_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        $launch = ["'/fixture/send mail' -t -i", [0 => ['pipe', 'w'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]];
        foreach ($cases as $index => [$case, $phase, $type, $reapType]) {
            $events = ['open'];
            $started = $phase !== 'open' && $case !== 'open-false';
            $invalid = strpos($case, 'missing-') === 0 || strpos($case, 'invalid-') === 0;
            $validated = strpos($phase, 'validate-') === 0;
            $write = null;
            if ($started) {
                if ($validated) $events[] = $phase;
                if (!$invalid && !$validated) {
                    $write = ["message\n", 'support mail message to sendmail'];
                    $events[] = 'write';
                    if ($phase !== 'write') {
                        $events[] = 'close stdin';
                        if ($phase !== 'close stdin') $events[] = 'read';
                    }
                }
                foreach (['stdin', 'stdout', 'stderr'] as $pipe => $name) {
                    if ($invalid && substr($case, -1) === (string) $pipe) continue;
                    if (!in_array('close '.$name, $events, true)) $events[] = 'close '.$name;
                }
                $events[] = 'reap';
            }
            $message = $reapType !== '' ? 'reap failure' : ($phase !== '' ? $phase.' failure'
                : ($case === 'open-false' ? 'Unable to start sendmail transport.'
                    : ($invalid ? 'Sendmail transport did not provide usable pipes.'
                        : ($case === 'exit' ? 'Support mail delivery failed: diagnostic' : null))));
            $this->assertSame([$message, true, false, $events, $launch, $write], $rows[$index], implode(':', $cases[$index]));
        }
    }
}
