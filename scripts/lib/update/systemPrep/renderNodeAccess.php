<?php
/** PMSS-managed iGPU render-node access (Intel i915/xe, AMD amdgpu) for shared hardware transcoding. */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../dpkgSelections.php';
require_once dirname(__DIR__, 2).'/user/selection.php';
require_once __DIR__.'/renderNodeDeny.php';

/** Install the iGPU (Intel and AMD) udev rule and apply it to existing DRM devices. Names keep "Intel" for callers. */
function pmssEnsureIntelRenderNodeAccess(?callable $logger = null, ?string $target = null, ?callable $commandRunner = null, ?callable $commandPath = null): void
{
    $log = $logger ?: 'logMessage';
    $target = $target ?? '/etc/udev/rules.d/70-pmss-render-node.rules';
    $content = "# PMSS-managed: allow all local accounts to use Intel and AMD iGPU render nodes.\n"
        ."SUBSYSTEM==\"drm\", KERNEL==\"renderD*\", DRIVERS==\"".implode('|', pmssRenderNodeDrivers())."\", MODE=\"0666\"\n";
    if (!pmssInstallManagedPathFile($target, $content, 'Intel render-node access rule', $log, ['mode' => 0644])) {
        return;
    }

    pmssUdevRulesApply('Intel render nodes', '[WARN] udevadm unavailable; Intel render-node rule will apply on the next device event', [
        ['Applying Intel render-node access', 'trigger --subsystem-match=drm --action=change', '[WARN] Unable to trigger DRM devices for Intel render-node access'],
    ], $log, $commandRunner, $commandPath);
}

/** Add one validated account to render when Intel or AMD iGPU hardware and the group exist. */
function pmssEnsureIntelRenderGroupMember(string $user, ?callable $logger = null, ?callable $intelProbe = null, ?callable $groupLookup = null, ?callable $commandRunner = null, ?callable $commandPath = null, ?callable $gpuEnabled = null): string
{
    $log = $logger ?: 'logMessage';
    $intelProbe = $intelProbe ?: 'pmssHostHasRenderGpuDisplayPciDevice';
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
    $gpuEnabled = $gpuEnabled ?: 'pmssUserGpuEnabled';
    if (!$gpuEnabled($user)) return 'skipped';
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
function pmssEnsureIntelRenderGroupMembers(?callable $logger = null, ?callable $intelProbe = null, ?callable $groupLookup = null, ?callable $userLister = null, ?callable $commandRunner = null, ?callable $commandPath = null, ?callable $gpuEnabled = null): void
{
    $log = $logger ?: 'logMessage';
    $intelProbe = $intelProbe ?: 'pmssHostHasRenderGpuDisplayPciDevice';
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
        $status = pmssEnsureIntelRenderGroupMember((string) $user, $log, static function (): bool { return true; }, $lookup, $commandRunner, $commandPath, $gpuEnabled);
        if ($status === 'added') {
            $added++;
            $group['members'][] = $user;
        } elseif ($status === 'member') {
            $members++;
        }
    }
    $log(sprintf('Intel render group: %d added, %d already members', $added, $members));
}
