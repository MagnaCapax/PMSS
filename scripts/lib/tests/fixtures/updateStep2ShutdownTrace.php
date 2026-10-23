<?php
/** Record shutdown decisions without loading the updater or executing host commands. */
namespace PMSS\Tests\Step2ShutdownTrace;

// Namespace stubs intercept every external effect in the extracted production functions.
function logmsg($message): void { $GLOBALS['trace'][] = ['log', $message]; }
function pmssLogJson($event): void { $GLOBALS['trace'][] = ['json', $event]; }
function pmssEnvFlagEnabled($key): bool { return !empty($GLOBALS['case']['dry']); }
function file_exists($path): bool { return empty($GLOBALS['case']['missing_nginx']); }
function is_file($path): bool { return empty($GLOBALS['case']['missing_permissions']); }
function error_get_last() { return $GLOBALS['case']['error'] ?? null; }
function pmssLockChildClosePrefix(): string { return 'close-lock-fds '; }
function register_shutdown_function($callback): void { $GLOBALS['guard'] = $callback; }
function passthru($command, &$rc): void
{
    $GLOBALS['trace'][] = ['passthru', $command];
    if (!empty($GLOBALS['case']['throw_web'])) throw new \RuntimeException('web failure');
    $rc = strpos($command, '--reload') !== false
        ? ($GLOBALS['case']['reload_rc'] ?? 0) : ($GLOBALS['case']['start_rc'] ?? 0);
}
function runStep($description, $command): int
{
    $GLOBALS['trace'][] = ['step', $description, $command];
    if (!empty($GLOBALS['case']['throw_permissions'])) throw new \RuntimeException('permission failure');
    return $GLOBALS['case']['permissions_rc'] ?? 0;
}

// Extract definitions only. The script body must never run, even on a testing machine.
$root = dirname(__DIR__, 4);
$module = $root.'/scripts/lib/update/runtime/shutdown.php';
$source = file_get_contents(\is_file($module) ? $module : $root.'/scripts/util/update-step2.php');
$start = strpos($source, 'function pmssUpdateStep2ShutdownReason(): string');
// The old entrypoint also contains registration; the module ends after definitions.
$end = strpos($source, "\npmssUpdateStep2RegisterShutdownGuard();");
if ($start === false) throw new \RuntimeException('Shutdown definitions not found');
$definitions = $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
eval('namespace '.__NAMESPACE__.';'.$definitions);

$cases = [
    'completed' => ['completed' => true, 'pending' => true],
    'dry_idle' => ['dry' => true],
    'permissions' => [],
    'permissions_missing' => ['missing_permissions' => true],
    'reload' => ['pending' => true, 'error' => ['message' => 'original failure']],
    'reload_failed' => ['pending' => true, 'reload_rc' => 9, 'start_rc' => 7, 'permissions_rc' => 4],
    'nginx_missing' => ['pending' => true, 'missing_nginx' => true],
    'dry_pending' => ['dry' => true, 'pending' => true],
    'dry_missing' => ['dry' => true, 'pending' => true, 'missing_nginx' => true],
    'empty_error' => ['error' => ['message' => '']],
    'nonstring_error' => ['error' => ['message' => 123]],
    'throw_web' => ['pending' => true, 'throw_web' => true],
    'throw_permissions' => ['throw_permissions' => true],
];
$traces = [];
foreach ($cases as $name => $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['trace'] = [];
    $GLOBALS['PMSS_UPDATE_STEP2_COMPLETED'] = $case['completed'] ?? false;
    $GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_PENDING'] = $case['pending'] ?? false;
    pmssUpdateStep2RegisterShutdownGuard();
    try { $GLOBALS['guard'](); }
    catch (\Throwable $error) { $GLOBALS['trace'][] = ['throw', get_class($error), $error->getMessage()]; }
    $traces[$name] = $GLOBALS['trace'];
}
echo json_encode($traces, JSON_UNESCAPED_SLASHES)."\n";
