<?php
/** Tracker-cleaner facade for the cron entrypoint and legacy library callers. */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['lighttpd/userFileWrite.php', 'user/identity.php']);
pmssRequireRelativeFiles(__DIR__, [
    'trackerCleaner/policy.php', 'trackerCleaner/log.php',
    'trackerCleaner/backup.php', 'trackerCleaner/run.php',
]);
