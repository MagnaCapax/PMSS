<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Candidate lifetime faults without creating files or invoking rsyslog. */
class RsyslogCandidateSafetyTest extends TestCase
{
    public function testCandidateCleanupCoversPreparationAndPreservesOperationOrder(): void
    {
        $script = <<<'PHP'
namespace RsyslogCandidateFixture;
function observe($stage) {
    $GLOBALS['events'][] = $stage;
    if ($GLOBALS['fault'] === $stage) throw $GLOBALS['failure'];
}
function pmssResolvePathFromEnv(...$args) { return '/fixture/rsyslog.conf'; }
function pmssReadRegularFileContents($path) { return "module(load=\"imklog\")\n"; }
function pmssManagedPathIsSafe(...$args) { return true; }
function tempnam($dir, $prefix) {
    observe('allocate');
    $GLOBALS['allocation'] = [$dir, $prefix];
    if ($GLOBALS['case'] === 'allocate-false') return false;
    return $GLOBALS['case'] === 'allocate-empty' ? '' : '/fixture/.pmss-rsyslog-candidate';
}
function pmssFileWriteComplete($path, $body) {
    observe('write');
    $GLOBALS['writes'][] = [$path, $body];
    return $GLOBALS['case'] !== 'write-false';
}
function chmod($path, $mode) {
    observe('mode');
    $GLOBALS['modes'][] = [$path, $mode];
    return $GLOBALS['case'] !== 'mode-false';
}
function unlink($path) { observe('remove'); $GLOBALS['removed'][] = $path; return true; }
function pmssCreateManagedPathBackup(...$args) { observe('backup'); return '/fixture/backup'; }
function pmssReplaceUserFilePreservingMetadata($path, $body) { observe('publish'); return true; }
function pmssSystemdActionSkipReason(...$args) { return 'test'; }
function pmssSystemdActionSkip(...$args) { observe('restart-gate'); return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/services/logging/rsyslog.php', 'RsyslogCandidateFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_RSYSLOG_CANDIDATE'), true) as [$case, $fault, $type]) {
    $GLOBALS['case'] = $case; $GLOBALS['fault'] = $fault;
    $GLOBALS['failure'] = $type === '' ? null : new $type('candidate boundary failure');
    $GLOBALS['events'] = $GLOBALS['writes'] = $GLOBALS['modes'] = $GLOBALS['removed'] = [];
    $GLOBALS['allocation'] = null;
    $caught = null; $validation = []; $messages = [];
    try {
        pmssApplyRsyslogKernelInputRateLimit(
            static function ($message) use (&$messages): void {
                $messages[] = $message;
                observe('log');
            },
            static function ($description, $command) use (&$validation): int {
                observe('validate');
                $validation[] = [$description, $command];
                return $GLOBALS['case'] === 'validate-false' ? 1 : 0;
            }
        );
    } catch (\Throwable $error) { $caught = $error; }
    $results[] = [$caught === $GLOBALS['failure'], $GLOBALS['events'], $GLOBALS['removed'],
        $GLOBALS['allocation'], $GLOBALS['writes'], $GLOBALS['modes'], $validation, $messages];
}
echo json_encode($results);
PHP;
        $warnings = [
            'allocate-false' => '[WARN] Unable to prepare rsyslog configuration candidate',
            'allocate-empty' => '[WARN] Unable to prepare rsyslog configuration candidate',
            'write-false' => '[WARN] Unable to prepare rsyslog configuration candidate',
            'mode-false' => '[WARN] Unable to secure rsyslog configuration candidate',
            'validate-false' => '[WARN] Rsyslog kernel input rate limit candidate failed validation; existing configuration preserved',
        ];
        $cases = [];
        foreach (array_merge(['ok'], array_keys($warnings)) as $case) {
            $cases[] = [$case, '', ''];
        }
        // Both exception families must release candidates; warning failures must not remove twice.
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach (['allocate', 'write', 'mode', 'validate'] as $fault) $cases[] = ['ok', $fault, $type];
            foreach (['write-false', 'mode-false'] as $case) $cases[] = [$case, 'log', $type];
        }
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_RSYSLOG_CANDIDATE' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        $path = '/fixture/.pmss-rsyslog-candidate';
        $body = "module(load=\"imklog\" RatelimitInterval=\"10\" RatelimitBurst=\"2000\")\n";
        // Explicit traces keep cleanup order independent of the implementation's branches.
        $plans = [
            'ok' => [['allocate', 'write', 'mode', 'validate', 'remove', 'backup', 'publish', 'log', 'restart-gate'], true, true, true, [$path]],
            'allocate-false' => [['allocate', 'log'], false, false, false, []],
            'allocate-empty' => [['allocate', 'remove', 'log'], false, false, false, ['']],
            'write-false' => [['allocate', 'write', 'remove', 'log'], true, false, false, [$path]],
            'mode-false' => [['allocate', 'write', 'mode', 'remove', 'log'], true, true, false, [$path]],
            'validate-false' => [['allocate', 'write', 'mode', 'validate', 'remove', 'log'], true, true, true, [$path]],
            'allocate' => [['allocate'], false, false, false, []],
            'write' => [['allocate', 'write', 'remove'], false, false, false, [$path]],
            'mode' => [['allocate', 'write', 'mode', 'remove'], true, false, false, [$path]],
            'validate' => [['allocate', 'write', 'mode', 'validate', 'remove'], true, true, false, [$path]],
        ];
        foreach ($cases as $index => [$case, $fault, $type]) {
            [$events, $written, $secured, $validated, $removed] = $plans[$fault !== '' && $fault !== 'log' ? $fault : $case];
            $writes = $written ? [[$path, $body]] : [];
            $modes = $secured ? [[$path, 0600]] : [];
            $validation = $validated ? [[
                'Validating rsyslog kernel input rate limit', 'rsyslogd -N1 -f '.escapeshellarg($path),
            ]] : [];
            $messages = isset($warnings[$case]) ? [$warnings[$case]] : ($fault !== '' ? [] : [
                'Applied rsyslog kernel input rate limit (10s/2000 messages; backup /fixture/backup)',
            ]);
            $this->assertSame([true, $events, $removed, $fault === 'allocate' ? null : ['/fixture', '.pmss-rsyslog-'],
                $writes, $modes, $validation, $messages], $results[$index], implode(':', [$case, $fault, $type]));
        }
    }
}
