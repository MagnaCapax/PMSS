<?php
/** CLI presentation and dispatch for the stable service-port facade. */

/** Emit the public error text and optionally mirror it to user logs. */
function pmssPortManagerFail(string $message, string $user = '', string $action = '', string $service = '', ?int $port = null, string $logMessage = ''): int
{
    fwrite(STDERR, $message);
    if ($logMessage !== '' && $user !== '' && $action !== '' && $service !== '') pmssPortManagerLog($user, $action, $service, $port, 'ERR', $logMessage);
    return 1;
}

/** Project transaction failures once, preserving the directory failure's lack of an audit. */
function pmssPortManagerCliFailure(string $status, string $user, string $action, string $service): int
{
    $failures = [
        'port_dir_unavailable' => ['unable to initialize port directory', ''],
        'lock_failed' => ['unable to acquire port lock', 'lock_failed'],
        'port_range_exhausted' => ['no free port available', 'port_range_exhausted'],
        'write_failed' => ['failed to persist port assignment', 'write_failed'],
        'release_failed' => ['failed to release port', 'release_failed'],
        'invalid_existing_assignment' => ['invalid stored port assignment', 'invalid_existing_assignment'],
    ];
    [$message, $audit] = $failures[$status] ?? ['invalid stored port assignment', 'unsafe_assignment_path'];
    return pmssPortManagerFail('Error: '.$message."\n", $user, $action, $service, null, $audit);
}

/** Keep one dispatch path for every CLI action. */
function pmssPortManagerMain(array $argv): int
{
    $usage = 'Usage: portManager.php [view|assign|release] USER [SERVICE]';
    $action = strtolower(trim((string) ($argv[1] ?? '')));
    if (count($argv) < 3 || !in_array($action, array('view', 'assign', 'release'), true)) {
        echo $usage;
        return 0;
    }

    $user = trim((string) $argv[2]);
    if (!pmssValidateUsername($user)) {
        return pmssPortManagerFail("Error: invalid username\n");
    }

    $service = isset($argv[3]) ? strtolower(trim((string) $argv[3])) : 'lighttpd';
    if (!pmssPortManagerServiceNameIsValid($service)) {
        return pmssPortManagerFail("Error: invalid service\n");
    }

    // Allocation reports after the helper has released its lock.
    if ($action === 'assign') {
        $assignStatus = '';
        $port = pmssPortManagerAssignServicePort($user, $service, null, $assignStatus);
        if ($port !== null) {
            echo $port;
            pmssPortManagerLog($user, $action, $service, $port, $assignStatus === 'already_assigned' ? 'SKIP' : 'OK', $assignStatus);
            return 0;
        }
        return pmssPortManagerCliFailure($assignStatus, $user, $action, $service);
    }

    if ($action === 'view') {
        $context = pmssPortManagerAssignmentContext($user, $service, $contextStatus);
        if ($context === null) return pmssPortManagerCliFailure($contextStatus, $user, $action, $service);
        if (!$context['present']) {
            echo 'No port assigned';
            return 0;
        }
        $assignedPort = pmssPortManagerReadAssignedPort($context['file']);
        if ($assignedPort === null) return pmssPortManagerFail("Error: invalid stored port assignment\n");
        echo $assignedPort;
        return 0;
    }

    // Release reports under the lock, including failed rechecks and audit writes.
    $result = pmssPortManagerAssignmentWithLock($user, $service, static function (?array $context, &$status) use ($user, $action, $service): int {
        if ($context === null || !pmssPortManagerReleaseAssignment($context, $status)) return pmssPortManagerCliFailure($status, $user, $action, $service);
        echo $status === 'not_assigned' ? 'No port assigned' : 'Port released';
        if ($status === 'released') pmssPortManagerLog($user, $action, $service, null, 'OK', 'released');
        return 0;
    }, $contextStatus);
    return $result ?? pmssPortManagerCliFailure($contextStatus, $user, $action, $service);
}
