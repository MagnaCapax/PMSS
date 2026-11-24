<?php
/** Verify route repair deadlines and shared backoff without launching commands. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LighttpdRouteConvergeTimeoutTest extends TestCase
{
    public function testRouteRepairsShareDeadlineFailuresAndBackoff(): void
    {
        $runtime = $this->pmssMakeTempDir('pmss-route-deadline-', 0700);
        $script = <<<'PHP'
namespace LighttpdRouteDeadlineFixture;
function pmssRuntimeDir() { return getenv('PMSS_TEST_ROUTE_RUNTIME'); }
function time() { return 2000000000; }
function pmssEnvFlagEnabled($key) { return false; }
function pmssLockChildClosePrefix() { return ''; }
function pmssReadRegularFileNetworkPort($path) { return null; }
function readlink($path) { return false; }
function is_file($path) { return false; }
function pmssReadRegularFileContentsVerified($path, ...$args) {
    if (strpos($path, pmssRuntimeDir().'/') === 0) return \pmssReadRegularFileContentsVerified($path, ...$args);
    return basename($path) === '.lighttpd.conf' ? $GLOBALS['config'] : '';
}
function pmssCommandPipedCapture($shell, $command, $seconds, $limit, $log) {
    $GLOBALS['commands'][] = [$command, $seconds, $limit, $log];
    return $GLOBALS['reply'];
}
function runStep($label, $command) { $GLOBALS['nginx'][] = $command; return 0; }
PHP;
        foreach (['scripts/lib/user/watchdogTimeout.php', 'scripts/lib/lighttpd/hermesUiConverge.php',
            'scripts/lib/lighttpd/transmissionProxy.php'] as $path) {
            $script .= $this->pmssInlinePhpLibraryInNamespace($path, 'LighttpdRouteDeadlineFixture');
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['hermes', 'transmission', 'chain'] as $route) {
    foreach (['success', 'failure', 'timeout', 'timeout-zero', 'matched', 'backoff', 'bad-user', 'bad-home'] as $case) {
        $marker = pmssUserWatchdogBackoffPath('fixtur01', 'lighttpd');
        if (\is_file($marker)) \unlink($marker);
        if ($case === 'backoff') pmssUserWatchdogBackoffRecord('fixtur01', 'lighttpd');
        $GLOBALS['commands'] = $GLOBALS['nginx'] = [];
        $GLOBALS['reply'] = ['rc' => $case === 'failure' ? 7 : ($case === 'timeout' ? 124 : 0),
            'timed_out' => $case === 'timeout' || $case === 'timeout-zero'];
        // No published selection: existing routes must be removed by a rebuild.
        $GLOBALS['config'] = $case === 'matched' ? 'fixture' : '"^/user-fixtur01/hermes/" "^/user-fixtur01/transmission/"';
        $user = $case === 'bad-user' ? '../fixtur01' : 'fixtur01';
        $home = $case === 'bad-home' ? '/home/fixtur02' : '/home/fixtur01';
        if ($route !== 'transmission') pmssHermesUiConverge($user, $home);
        if ($route !== 'hermes') pmssLighttpdTransmissionRouteConverge($user, $home);
        $rows[$route][$case] = [$GLOBALS['commands'], count($GLOBALS['nginx']),
            pmssUserWatchdogBackoffActive('fixtur01', 'lighttpd')];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_ROUTE_RUNTIME' => $runtime]);
        foreach ($rows as $route => $cases) {
            foreach ($cases as $case => [$commands, $nginx, $backoff]) {
                $skipped = in_array($case, ['matched', 'backoff', 'bad-user', 'bad-home'], true);
                $timedOut = $case === 'timeout' || $case === 'timeout-zero';
                $count = $skipped ? 0 : ($route === 'chain' && !$timedOut ? 2 : 1);
                $this->assertSame($count, count($commands), $route.' '.$case);
                foreach ($commands as [$command, $seconds, $limit, $log]) {
                    $this->assertSame("/scripts/util/userConfigLighttpd.php 'fixtur01'", $command);
                    $this->assertSame([60, 1048576, true], [$seconds, $limit, $log]);
                }
                $this->assertSame($timedOut || $case === 'backoff', $backoff, $route.' '.$case.' backoff');
                $this->assertSame($route !== 'transmission' && in_array($case, ['success', 'matched'], true) ? 2 : 0,
                    $nginx, $route.' '.$case.' nginx');
            }
        }
        $source = $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php');
        $this->assertSame(3, substr_count($source, "60, \$thisUser, 'lighttpd'"));
    }
}
