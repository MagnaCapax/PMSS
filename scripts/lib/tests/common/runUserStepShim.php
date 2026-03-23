<?php
/**
 * Lazily installed runUserStep shim for hermetic tests.
 *
 * This file stays in the global namespace so tests can provide the same
 * function signature the update runtime expects without forcing the shim to
 * exist before a test explicitly opts in.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('runUserStep')) {
    function runUserStep(string $user, string $description, string $command): int
    {
        $mode = (string) ($GLOBALS['PMSS_TEST_RUNUSERSTEP_MODE'] ?? 'noop');
        if ($mode === 'profile') {
            $GLOBALS['PMSS_PROFILE'][] = ['description' => $description, 'command' => $command];
        } elseif ($mode === 'last') {
            $GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST'] = ['user' => $user, 'description' => $description, 'command' => $command];
        }

        return 0;
    }
}
