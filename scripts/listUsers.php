#!/usr/bin/env php
<?php
/**
 * List managed PMSS users (one per line).
 *
 * Outputs validated usernames whose homes live under /home so automation and
 * cron jobs can consume a simple list. Any anomalous entries are logged and
 * skipped. On internal failures (missing libs, missing posix, etc.) this
 * script exits non-zero and prints a single error line instead of a stack
 * trace to keep downstream consumers safer.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
$usersLib = __DIR__.'/lib/users.php';
$userFs   = __DIR__.'/lib/user/userFilesystem.php';
$userRepo = __DIR__.'/lib/user/userRepository.php';

// Fail fast with a single, clearly marked error when core dependencies are
// missing or the environment lacks required extensions. This avoids emitting
// PHP fatals/stack traces that downstream consumers might misinterpret.
if (!is_file($usersLib) || !is_file($userFs) || !is_file($userRepo)) {
    fwrite(STDERR, "Error: listUsers.php dependencies missing; aborting.\n");
    exit(1);
}
if (!function_exists('posix_getpwnam')) {
    fwrite(STDERR, "Error: posix_getpwnam() unavailable; listUsers.php cannot run safely.\n");
    exit(1);
}

require_once $usersLib;
require_once __DIR__.'/lib/userLifecycle.php';

$db = new users();
$db->getUsers(); // prime cache and prune stale records

$usernames = users::listHomeUsers();
sort($usernames, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($usernames as $name) {
    if ($name === '') {
        continue;
    }
    if (!pmssValidateUsername($name)) {
        // Record and skip unexpected entries so downstream consumers see only
        // valid usernames while operators can investigate anomalies.
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'list',
                'filter_invalid',
                $name,
                array(
                    'status'  => 'ERR',
                    'message' => 'Skipping invalid username from listHomeUsers',
                )
            )
        );
        continue;
    }
    echo $name . "\n";
}
