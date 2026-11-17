<?php
/**
 * Customer-state migration entrypoint and logging for web-root recovery.
 *
 * Move mutable web content below the durable home paths before linking it back
 * into www. The move is deliberately conservative: conflicts and symlinks are
 * left untouched so a later reconciliation pass cannot lose customer data.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once dirname(__DIR__, 2).'/runtime/filesystem.php';
require_once dirname(__DIR__, 2).'/user/directories.php';
require_once dirname(__DIR__, 2).'/lighttpd/accountPath.php';
require_once __DIR__.'/webRootInspection.php';
require_once __DIR__.'/webRootPaths.php';
require_once __DIR__.'/webRootAccount.php';
require_once __DIR__.'/webRootMisfile.php';
require_once __DIR__.'/webRootMove.php';

/** Emit one migration message through the normal updater logger or a test logger. */
function pmssUserWebRootMigrationLog(string $user, ?callable $logger, string $message): void
{
    ($logger ?? 'logMessage')("[user:{$user}] {$message}");
}

/** Migrate the customer-owned web paths whose durable targets are ADR-defined. */
function pmssUserMigrateWebRootState(array $ctx, ?callable $logger = null): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    if ($user === '' || $home === '') {
        return;
    }
    $log = $logger ?: 'logMessage';

    foreach ([
        ['www/public', '.local/share/pmss/public', 'public'],
        ['www/rutorrent/share', '.local/share/pmss/rutorrent/share', 'share'],
    ] as $entry) {
        pmssUserMigrateWebRootBasenameCollapsePath($user, $home, $entry[0], $entry[1], $entry[2], $log);
        pmssUserMigrateWebRootPath($user, $home, $entry[0], $entry[1], $log);
    }
}
