<?php
/**
 * Shared helpers for PMSS user lifecycle operations.
 *
 * Composes identity, selection, watchdog, audit, web-root, and service helpers
 * for legacy add/suspend/unsuspend/terminate flows (ADR 0187).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/log.php';
require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['user/identity.php', 'user/selection.php', 'user/watchdog.php', 'user/userFilesystem.php', 'user/userConfigStore.php',
    'user/lifecycleLog.php', 'user/lifecycleWebRoot.php', 'user/lifecycleServices.php',
]);
