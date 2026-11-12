<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsIncAppProcess.php';

/** Fault coverage for streams owned by etc/skel/www/scriptsIncAppProcess.php. */
class CustomerAppProcessCleanupSafetyTest extends TestCase
{
    public function testNativeHeaderInspectionClosesBeforeScanningOrRethrowing(): void
    {
        // Export only the reader; memory streams and synthetic proc results avoid host observations.
        $script = <<<'PHP'
namespace CustomerAppHeaderFixture;
function readlink($path) { return 'fixture-mount'; }
function realpath($path) {
    $GLOBALS['events'][] = ['resolve', $path];
    return $GLOBALS['case'] === 'missing' ? false : $path;
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['case'] === 'open-false' ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function fgets($handle, $length) {
    $GLOBALS['events'][] = ['read', $length];
    if (in_array($GLOBALS['case'], ['read-throw', 'read-close-throw'], true)) throw $GLOBALS['failure'];
    return ['native' => "ELF\0", 'script' => "#!/usr/bin/python3\n", 'env' => "#!/usr/bin/env python3\n",
        'empty' => '', 'short' => '#!', 'read-false' => false][$GLOBALS['case']] ?? '';
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (in_array($GLOBALS['case'], ['close-throw', 'read-close-throw'], true)) throw $GLOBALS['closeFailure'];
    return true;
}
function glob($pattern, $flags) { $GLOBALS['events'][] = ['scan', $pattern, $flags]; return []; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssCustomerNativePidsRead', 'CustomerAppHeaderFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['native', 'script', 'env', 'empty', 'short', 'missing', 'open-false', 'read-false',
    'read-throw', 'close-throw', 'read-close-throw'] as $case) {
    foreach (strpos($case, 'throw') === false ? [null] : ['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $GLOBALS['failure'] = $kind === null ? null : new $kind('read fault');
        $GLOBALS['closeFailure'] = $kind === null ? null : new $kind('close fault');
        $caught = $result = null;
        try { $result = pmssCustomerNativePidsRead(['/fixture/app'], '/fixture/proc', 1001); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = in_array($case, ['close-throw', 'read-close-throw'], true)
            ? $GLOBALS['closeFailure'] : $GLOBALS['failure'];
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']);
        $results[] = [$case, $result, $caught === $expectedFailure, $live, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(14, count($results));
        foreach ($results as [$case, $result, $sameFailure, $live, $events]) {
            $expected = [['resolve', '/fixture/app']];
            if ($case !== 'missing') $expected[] = ['open', '/fixture/app', 'rb'];
            if (!in_array($case, ['missing', 'open-false'], true)) {
                $expected[] = ['read', 160];
                $expected[] = ['close'];
            }
            if (in_array($case, ['script', 'env'], true)) $expected[] = ['resolve', '/usr/bin/python3'];
            $throws = strpos($case, 'throw') !== false;
            if (!$throws) $expected[] = ['scan', '/fixture/proc/*', GLOB_ONLYDIR];
            $this->assertSame([$throws ? null : [], true, false, $expected],
                [$result, $sameFailure, $live, $events], $case);
        }
    }

    public function testToggleLockClosesAfterAcquisitionLaunchAndUnlockFaults(): void
    {
        // Deluge uses the same lock lifetime without loading qBittorrent configuration helpers.
        $script = <<<'PHP'
namespace CustomerAppLockFixture;
function is_dir($path) { return $GLOBALS['case'] !== 'no-directory'; }
function is_link($path) {
    return strpos($GLOBALS['case'], 'selected') === 0 && substr($path, -8) === '/default';
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    if ($GLOBALS['case'] === 'open-throw') throw $GLOBALS['failure'];
    return $GLOBALS['case'] === 'open-false' ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function flock($handle, $operation) {
    $GLOBALS['events'][] = [$operation === LOCK_UN ? 'unlock' : 'lock', $operation];
    if ($operation === LOCK_UN) {
        if (strpos($GLOBALS['case'], 'unlock-throw') !== false || $GLOBALS['case'] === 'unlock-close-throw')
            throw $GLOBALS['unlockFailure'];
        return $GLOBALS['case'] !== 'unlock-false';
    }
    if ($GLOBALS['case'] === 'lock-throw') throw $GLOBALS['failure'];
    return $GLOBALS['case'] !== 'busy';
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (strpos($GLOBALS['case'], 'close-throw') !== false) throw $GLOBALS['closeFailure'];
    return true;
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssCustomerTorrentClientToggleStart', 'CustomerAppLockFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['system', 'selected', 'selected-false', 'no-directory', 'open-false', 'busy', 'unlock-false',
    'open-throw', 'lock-throw', 'launch-throw', 'selected-throw', 'unlock-throw', 'close-throw',
    'launch-unlock-throw', 'unlock-close-throw', 'launch-close-throw'] as $case) {
    foreach (strpos($case, 'throw') === false ? [null] : ['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $GLOBALS['failure'] = $kind === null ? null : new $kind('operation fault');
        $GLOBALS['unlockFailure'] = $kind === null ? null : new $kind('unlock fault');
        $GLOBALS['closeFailure'] = $kind === null ? null : new $kind('close fault');
        $system = static function (): void {
            $GLOBALS['events'][] = ['system'];
            if (strpos($GLOBALS['case'], 'launch-') === 0) throw $GLOBALS['failure'];
        };
        $selected = static function ($command): bool {
            $GLOBALS['events'][] = ['selected', $command];
            if ($GLOBALS['case'] === 'selected-throw') throw $GLOBALS['failure'];
            return $GLOBALS['case'] !== 'selected-false';
        };
        $caught = $result = null;
        try { $result = pmssCustomerTorrentClientToggleStart('deluge', $system, '/fixture', $selected); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = strpos($case, 'close-throw') !== false ? $GLOBALS['closeFailure']
            : (strpos($case, 'unlock-throw') !== false ? $GLOBALS['unlockFailure'] : $GLOBALS['failure']);
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']);
        $results[] = [$case, $result, $caught === $expectedFailure, $live, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(25, count($results));
        foreach ($results as [$case, $result, $sameFailure, $live, $events]) {
            $expected = [];
            if ($case !== 'no-directory') $expected[] = ['open', '/fixture/.config/pmss/torrentClients/deluge.lock', 'ce'];
            if (!in_array($case, ['no-directory', 'open-false', 'open-throw'], true)) $expected[] = ['lock', LOCK_EX | LOCK_NB];
            if (!in_array($case, ['open-false', 'open-throw', 'busy', 'lock-throw'], true)) {
                $expected[] = strpos($case, 'selected') === 0
                    ? ['selected', 'cd ~; php ~/www/torrentClientsResolve.php deluge --launch both >/dev/null 2>&1']
                    : ['system'];
            }
            if (!in_array($case, ['no-directory', 'open-false', 'open-throw'], true)) {
                if ($case !== 'busy') $expected[] = ['unlock', LOCK_UN];
                $expected[] = ['close'];
            }
            $expectedResult = strpos($case, 'throw') !== false ? null
                : !in_array($case, ['open-false', 'busy', 'selected-false'], true);
            $this->assertSame([$expectedResult, true, false, $expected],
                [$result, $sameFailure, $live, $events], $case);
        }
    }
}
