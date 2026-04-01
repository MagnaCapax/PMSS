<?php
/**
 * addUser: rollback partially created accounts after early provisioning failure.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/orphanCleanup.php';

/**
 * Register process-local rollback state for the current addUser run.
 */
function pmssAddUserFailureRollbackInit(users $userDb, string $userName, string $homePath): void
{
    $GLOBALS['PMSS_ADDUSER_FAILURE_ROLLBACK'] = array(
        'userDb' => $userDb,
        'user' => $userName,
        'home' => $homePath,
        'systemUserCreated' => false,
        'userConfigApplied' => false,
        'cleanupAttempted' => false,
    );
    register_shutdown_function('pmssAddUserFailureRollbackShutdown');
}

/**
 * Return the current process-local rollback state.
 *
 * @return array<string,mixed>
 */
function pmssAddUserFailureRollbackStateRead(): array
{
    $state = $GLOBALS['PMSS_ADDUSER_FAILURE_ROLLBACK'] ?? array();
    return is_array($state) ? $state : array();
}

/**
 * Persist the current process-local rollback state.
 */
function pmssAddUserFailureRollbackStateWrite(array $state): void
{
    $GLOBALS['PMSS_ADDUSER_FAILURE_ROLLBACK'] = $state;
}

/**
 * Mark that useradd succeeded and the Unix account now exists.
 */
function pmssAddUserFailureRollbackMarkSystemUserCreated(): void
{
    $state = pmssAddUserFailureRollbackStateRead();
    $state['systemUserCreated'] = true;
    pmssAddUserFailureRollbackStateWrite($state);
}

/**
 * Mark that userConfig.php finished, so rollback must no longer remove the home.
 */
function pmssAddUserFailureRollbackMarkUserConfigApplied(): void
{
    $state = pmssAddUserFailureRollbackStateRead();
    $state['userConfigApplied'] = true;
    pmssAddUserFailureRollbackStateWrite($state);
}

/**
 * Return whether shutdown rollback should remove the partially created account.
 *
 * @param array<string,mixed>|null $state
 * @param array<string,mixed>|null $summary
 */
function pmssAddUserFailureRollbackShouldRun(?array $state = null, ?array $summary = null): bool
{
    $state = is_array($state) ? $state : pmssAddUserFailureRollbackStateRead();
    if (empty($state['systemUserCreated']) || !empty($state['userConfigApplied']) || !empty($state['cleanupAttempted'])) {
        return false;
    }

    $summary = is_array($summary) ? $summary : pmssAddUserProvisionSummaryGet();
    if (!is_array($summary)) {
        return false;
    }

    return ($summary['status'] ?? '') === 'FAIL' && (int) ($summary['exit_code'] ?? 0) !== 0;
}

/**
 * Execute the early-failure rollback and warn when stale state remains.
 */
function pmssAddUserFailureRollbackRun(): bool
{
    $state = pmssAddUserFailureRollbackStateRead();
    if (!pmssAddUserFailureRollbackShouldRun($state)) {
        return false;
    }

    $state['cleanupAttempted'] = true;
    pmssAddUserFailureRollbackStateWrite($state);
    logProvisionMessage('Provisioning failed before userConfig completed; removing partially created account and home directory');

    $userDb = $state['userDb'] ?? null;
    if (!$userDb instanceof users) {
        logProvisionMessage('WARNING: Failed provisioning rollback missing user database handle');
        return false;
    }

    $cleaned = pmssAddUserCleanupFailedProvision($userDb, (string) $state['user'], (string) $state['home']);
    if (!$cleaned) {
        logProvisionMessage('WARNING: Failed provisioning rollback left stale resources behind');
    }

    return $cleaned;
}

/**
 * Shutdown hook that reuses the shared stale-account cleanup path.
 */
function pmssAddUserFailureRollbackShutdown(): void
{
    pmssAddUserFailureRollbackRun();
}
