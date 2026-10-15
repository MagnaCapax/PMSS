<?php
/** PMSS-managed Intel render-node access for shared hardware transcoding. */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../dpkgSelections.php';
require_once dirname(__DIR__, 2).'/user/selection.php';

/** Install the Intel-only udev rule and apply it to existing DRM devices. */
function pmssEnsureIntelRenderNodeAccess(?callable $logger = null, ?string $target = null, ?callable $commandRunner = null, ?callable $commandPath = null): void
{
    $log = $logger ?: 'logMessage';
    $target = $target ?? '/etc/udev/rules.d/70-pmss-render-node.rules';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    $content = "# PMSS-managed: allow all local accounts to use Intel render nodes.\n"
        ."SUBSYSTEM==\"drm\", KERNEL==\"renderD*\", DRIVERS==\"i915|xe\", MODE=\"0666\"\n";
    $label = 'Intel render-node access rule';
    $options = pmssManagedPathInstallOptions($target, $label, ['mode' => 0644]);
    if (!pmssRefreshManagedPathFile($target, $content, $label, $log, $options)) {
        return;
    }

    $udevadm = $commandPath('udevadm');
    if ($udevadm === '') {
        $log('[WARN] udevadm unavailable; Intel render-node rule will apply on the next device event');
        return;
    }
    if ($commandRunner('Reloading udev rules for Intel render nodes', escapeshellarg($udevadm).' control --reload') !== 0) {
        $log('[WARN] Unable to reload udev rules for Intel render nodes');
        return;
    }
    if ($commandRunner('Applying Intel render-node access', escapeshellarg($udevadm).' trigger --subsystem-match=drm --action=change') !== 0) {
        $log('[WARN] Unable to trigger DRM devices for Intel render-node access');
    }
}

/** Add one validated account to render when Intel hardware and the group exist. */
function pmssEnsureIntelRenderGroupMember(string $user, ?callable $logger = null, ?callable $intelProbe = null, ?callable $groupLookup = null, ?callable $commandRunner = null, ?callable $commandPath = null): string
{
    $log = $logger ?: 'logMessage';
    $intelProbe = $intelProbe ?: 'pmssHostHasIntelDisplayPciDevice';
    $groupLookup = $groupLookup ?: 'posix_getgrnam';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    if (!$intelProbe()) return 'skipped';
    $group = $groupLookup('render');
    if (!is_array($group) || !is_array($group['members'] ?? null)) return 'skipped';
    if (!pmssValidateUsername($user)) {
        $log('[WARN] Invalid account name skipped for render group');
        return 'skipped';
    }
    if (in_array($user, $group['members'], true)) return 'member';

    $gpasswd = $commandPath('gpasswd');
    $binary = $gpasswd !== '' ? $gpasswd : $commandPath('usermod');
    if ($binary === '') {
        $log('[WARN] No group membership command available for render');
        return 'failed';
    }
    $command = $gpasswd !== ''
        ? escapeshellarg($binary).' -a '.escapeshellarg($user).' render'
        : escapeshellarg($binary).' -aG render '.escapeshellarg($user);
    if ($commandRunner('Adding account to render group', $command) !== 0) {
        $log('[WARN] Unable to add account to render group: '.pmssLogSafeLabel($user));
        return 'failed';
    }
    return 'added';
}

/** Converge all PMSS accounts independently of per-user refresh resume markers. */
function pmssEnsureIntelRenderGroupMembers(?callable $logger = null, ?callable $intelProbe = null, ?callable $groupLookup = null, ?callable $userLister = null, ?callable $commandRunner = null, ?callable $commandPath = null): void
{
    $log = $logger ?: 'logMessage';
    $intelProbe = $intelProbe ?: 'pmssHostHasIntelDisplayPciDevice';
    $groupLookup = $groupLookup ?: 'posix_getgrnam';
    if (!$intelProbe()) return;
    $group = $groupLookup('render');
    if (!is_array($group) || !is_array($group['members'] ?? null)) return;
    $userLister = $userLister ?: 'pmssManagedHomeUsersList';
    $added = 0;
    $members = 0;
    foreach ($userLister() as $user) {
        // Keep successful additions in the snapshot for duplicate list entries.
        $lookup = static function (string $name) use (&$group): array { return $group; };
        $status = pmssEnsureIntelRenderGroupMember((string) $user, $log, static function (): bool { return true; }, $lookup, $commandRunner, $commandPath);
        if ($status === 'added') {
            $added++;
            $group['members'][] = $user;
        } elseif ($status === 'member') {
            $members++;
        }
    }
    $log(sprintf('Intel render group: %d added, %d already members', $added, $members));
}
