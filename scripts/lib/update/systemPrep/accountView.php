<?php
/** Opt-in per-session account view artifacts and update-time reconciliation. */
require_once __DIR__.'/../managedPath.php';

/** The three ordered PAM session rules installed as an Additional profile. */
function pmssAccountViewSessionRules(): array
{
    return [
        'session [success=2 default=ignore] pam_succeed_if.so quiet uid < 1000',
        'session [success=1 default=ignore] pam_succeed_if.so quiet service = systemd-user',
        'session required pam_namespace.so',
    ];
}

/** Registry of all files managed by this feature. */
function pmssAccountViewArtifacts(string $root = ''): array
{
    $generator = (string) @file_get_contents(__DIR__.'/accountViewGenerator.php');
    $profileRules = array_map(static function (string $rule): string { return substr($rule, strlen('session ')); }, pmssAccountViewSessionRules());
    $profile = "Name: PMSS per-session account view\nDefault: yes\nPriority: 0\nSession-Type: Additional\nSession:\n\t"
        .implode("\n\t", $profileRules)."\n";
    $init = <<<'SH'
#!/bin/sh
uid=$(/usr/bin/id -u -- "$4") || /bin/kill -KILL $$
dir=/var/lib/pmss/account-view/$uid
for f in passwd group subuid subgid passwd- group- subuid- subgid-; do
    [ -e "/etc/$f" ] || continue
    if [ ! -f "$dir/$f" ] || [ "$(/usr/bin/stat -c %y -- "/etc/$f")" != "$(/usr/bin/stat -c %y -- "$dir/$f")" ]; then
        /usr/bin/php /usr/local/libexec/pmss-account-view.php "$4" || {
            for required in passwd group subuid subgid; do
                [ -f "$dir/$required" ] || /bin/kill -KILL $$
            done
        }
        break
    fi
done
for f in passwd group subuid subgid passwd- group- subuid- subgid-; do
    [ -e "/etc/$f" ] || continue
    [ -f "$dir/$f" ] || /bin/kill -KILL $$
    /bin/mount --bind "$dir/$f" "/etc/$f" || /bin/kill -KILL $$
    /bin/mount -o remount,bind,ro "/etc/$f" || /bin/kill -KILL $$
done
SH;
    $unit = "[Unit]\nDescription=PMSS root mount propagation\nDefaultDependencies=no\nAfter=local-fs.target\nBefore=systemd-logind.service cron.service ssh.service\n"
        ."[Service]\nType=oneshot\nExecStart=/bin/mount --make-private /\nRemainAfterExit=yes\n"
        ."[Install]\nWantedBy=multi-user.target\n";
    $dropin = "[Service]\nExecStartPre=-+/usr/bin/php /usr/local/libexec/pmss-account-view.php --uid %i\n";
    foreach (['passwd', 'group', 'subuid', 'subgid', 'passwd-', 'group-', 'subuid-', 'subgid-'] as $file) {
        $dropin .= 'BindReadOnlyPaths=-/var/lib/pmss/account-view/%i/'.$file.':/etc/'.$file."\n";
    }
    return [
        $root.'/usr/share/pam-configs/pmss-account-view' => [$profile, 0644],
        $root.'/etc/security/namespace.d/pmss-account-view.conf' => ["/etc/pmss-session pmss tmpfs:mntopts=size=4k,nosuid,nodev,noexec:iscript=/etc/security/namespace.d/pmss-account-view.init\n", 0644],
        $root.'/etc/security/namespace.d/pmss-account-view.init' => [$init."\n", 0755],
        $root.'/usr/local/libexec/pmss-account-view.php' => [$generator, 0644],
        $root.'/etc/systemd/system/user@.service.d/31-pmss-account-view.conf' => [$dropin, 0644],
        $root.'/etc/systemd/system/pmss-mount-propagation.service' => [$unit, 0644],
    ];
}

/** Verify the mount layout before the profile can affect a new session. */
function pmssAccountViewMountsSupported(): bool
{
    return trim((string) @shell_exec('/usr/bin/findmnt -n -o TARGET --target /etc/passwd 2>/dev/null')) === '/'
        && trim((string) @shell_exec('/usr/bin/findmnt -n -o TARGET --target /home 2>/dev/null')) === '/home';
}

/** Confirm PAM rendered all three rules in order in both session stacks. */
function pmssAccountViewPamRendered(string $root = ''): bool
{
    foreach (['common-session', 'common-session-noninteractive'] as $file) {
        $raw = @file_get_contents($root.'/etc/pam.d/'.$file);
        if (!is_string($raw)) return false;
        $raw = (string) preg_replace('/[ \t]+/', ' ', $raw);
        $position = 0;
        foreach (pmssAccountViewSessionRules() as $rule) {
            $found = strpos($raw, $rule, $position);
            if ($found === false) return false;
            $position = $found + strlen($rule);
        }
    }
    return true;
}

/** Remove only numeric view directories and files created by this feature. */
function pmssAccountViewRemoveViews(string $root = ''): void
{
    $base = $root.'/var/lib/pmss/account-view';
    if (!is_dir($base) || is_link($base)) return;
    $owner = (function_exists('posix_geteuid') && posix_geteuid() !== 0) ? posix_geteuid() : 0;
    if (fileowner($base) !== $owner || (fileperms($base) & 0777) !== 0700) return;
    foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (is_link($dir) || !ctype_digit(basename($dir))) continue;
        if (fileowner($dir) !== $owner || (fileperms($dir) & 0777) !== 0700) continue;
        foreach (['passwd', 'group', 'subuid', 'subgid', 'passwd-', 'group-', 'subuid-', 'subgid-', '.lock'] as $file) {
            $path = $dir.'/'.$file;
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
        @rmdir($dir);
    }
    @rmdir($base);
}

/** Reconcile the marker. Disabled hosts with no profile incur no PAM operation. */
function pmssAccountViewApply(?callable $logger = null, string $root = '', ?bool $mountsSupported = null): bool
{
    $log = $logger ?: 'logMessage';
    $artifacts = pmssAccountViewArtifacts($root);
    $profile = $root.'/usr/share/pam-configs/pmss-account-view';
    $enabled = is_file($root.'/etc/seedbox/config/account-view.enabled');
    $test = $root !== '';
    if (!$enabled) {
        if (!is_file($profile)) return true;
        if (!$test) runStep('Removing per-session account view PAM profile', 'pam-auth-update --package --remove pmss-account-view');
        if (!$test) runStep('Disabling PMSS mount propagation unit', 'systemctl disable --now pmss-mount-propagation.service');
        foreach (array_keys($artifacts) as $path) {
            if (is_file($path) && !@unlink($path)) $log('[WARN] Unable to remove account view artifact: '.$path);
        }
        @rmdir($root.'/etc/pmss-session');
        pmssAccountViewRemoveViews($root);
        if (!$test) {
            runStep('Reloading systemd after account view removal', 'systemctl daemon-reload');
        }
        return true;
    }
    if (!$test && (!function_exists('pmssDetectDistro') || (int) (pmssDetectDistro()['version'] ?? 0) < 12)) {
        $log('[WARN] Per-session account view not active: requires Debian 12 or later');
        return false;
    }
    if ($mountsSupported === null) $mountsSupported = $test ? false : pmssAccountViewMountsSupported();
    if (!$mountsSupported) {
        $log('[WARN] Per-session account view not active: requires /etc/passwd on / and separate /home mount');
        return false;
    }
    $anchor = $root.'/etc/pmss-session';
    if (!pmssDirEnsureExists($anchor, 0755) || is_link($anchor)) return false;
    // Install the profile last, after its runtime files have converged.
    $profileSpec = $artifacts[$profile];
    unset($artifacts[$profile]);
    foreach ($artifacts as $path => $spec) {
        $ok = false;
        pmssRefreshManagedPathFile($path, $spec[0], 'account view artifact', $log,
            pmssManagedPathInstallOptions($path, 'account view artifact', ['mode' => $spec[1]]), $ok);
        if (!$ok) return false;
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== $spec[1] && !@chmod($path, $spec[1])) return false;
    }
    if (!$test) {
        if (runStep('Reloading systemd for account view', 'systemctl daemon-reload') !== 0 ||
            runStep('Enabling PMSS mount propagation unit', 'systemctl enable --now pmss-mount-propagation.service') !== 0) {
            $log('[WARN] Per-session account view not enabled by this run: mount propagation unit unavailable');
            return false;
        }
    }
    $ok = false;
    pmssRefreshManagedPathFile($profile, $profileSpec[0], 'account view PAM profile', $log,
        pmssManagedPathInstallOptions($profile, 'account view PAM profile', ['mode' => $profileSpec[1]]), $ok);
    if (!$ok) return false;
    clearstatcache(true, $profile);
    if ((fileperms($profile) & 0777) !== $profileSpec[1] && !@chmod($profile, $profileSpec[1])) return false;
    if (!$test) {
        runStep('Installing per-session account view PAM profile', 'pam-auth-update --package --enable pmss-account-view');
        if (!pmssAccountViewPamRendered()) {
            $log('[WARN] Per-session account view not active: PAM session rules were not rendered');
            return false;
        }
    }
    return true;
}
