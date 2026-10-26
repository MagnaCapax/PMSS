<?php
/** Assignment transactions shared by service-port allocation and release. */

/** Preflight, lock, then re-read the assignment; callbacks retain outcome reporting policy. */
function pmssPortManagerAssignmentWithLock(string $user, string $service, callable $operation, &$status)
{
    if (pmssPortManagerAssignmentContext($user, $service, $status) === null) return null;
    $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager.lock'));
    if ($lockHandle === false) { $status = 'lock_failed'; return null; }
    try {
        // Always deliver the current context, including a failed recheck, under the lock.
        $context = pmssPortManagerAssignmentContext($user, $service, $status);
        return $operation($context, $status);
    } finally {
        pmssLockHandleRelease($lockHandle);
    }
}

/** Release the current locked assignment without interpreting its stored port bytes. */
function pmssPortManagerReleaseAssignment(array $context, &$status): bool
{
    if (!$context['present']) { $status = 'not_assigned'; return true; }
    if (!pmssPortManagerAssignmentPathIsSafe($context['dir'], $context['file'])) { $status = 'unsafe_assignment_path'; return false; }
    if (!@unlink($context['file'])) { $status = 'release_failed'; return false; }
    $status = 'released';
    return true;
}
