<?php
/**
 * User-facing cgroup memory pressure helpers for first-party web pages.
 *
 * Reads the account slice counters directly from cgroupfs and returns a small,
 * render-friendly status array without shelling out.
 *
 * Lives in etc/skel/www/ (customer tree) because customer PHP runs as the
 * customer UID and cannot traverse /scripts/ (the operator-only tree). The
 * cgroup files read here (/sys/fs/cgroup/user.slice/user-<UID>.slice/memory.*)
 * are world-readable kernel paths; the customer can read their own slice.
 *
 * Uses the customer-side pmssFormatBytes copy from scriptsInc.php because
 * /scripts/lib/runtime.php is intentionally outside the customer PHP boundary.
 *
 * Source, policy, payload and Welcome rendering are loaded together below.
 * Partial heal delivery retains the pages' existing unavailable fallbacks.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';
$pmssMemoryFiles = array(
    'webCgroupMemorySource.php', 'webCgroupMemoryPolicy.php', 'webCgroupMemoryRead.php', 'welcomeMemory.php',
);
if (function_exists('pmssWelcomeRequireLocalHelpers')) {
    if (!pmssWelcomeRequireLocalHelpers($pmssMemoryFiles)) return;
} else {
    // Older delivered loaders only have the checked single-file API; preflight the whole group first.
    if (!function_exists('pmssWelcomeRequireLocalHelper')) return;
    foreach ($pmssMemoryFiles as $pmssMemoryFile) {
        $pmssMemoryPath = __DIR__.'/'.$pmssMemoryFile;
        if (!is_file($pmssMemoryPath) || is_link($pmssMemoryPath) || !is_readable($pmssMemoryPath)) return;
    }
    foreach ($pmssMemoryFiles as $pmssMemoryFile) {
        if (!pmssWelcomeRequireLocalHelper($pmssMemoryFile)) return;
    }
}
unset($pmssMemoryFiles, $pmssMemoryFile, $pmssMemoryPath);
