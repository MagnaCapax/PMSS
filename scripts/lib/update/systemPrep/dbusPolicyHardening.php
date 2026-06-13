<?php
/**
 * D-Bus / runtime system policy hardening for update-step2 system preparation.
 *
 * systemd runs as root and answers its D-Bus calls for any logged-in user,
 * bypassing the /proc hidepid hardening. This module installs PMSS-managed
 * drop-ins that close three independent cross-user disclosure channels verified
 * on shared seedbox hosts (ticket #112008):
 *
 *   A. org.freedesktop.systemd1 manager enumeration (ListUnits*, GetProcesses,
 *      GetUnitProcesses, Dump) — the worst: process trees carry full command
 *      lines (rclone remotes, watch dirs, rootless-docker state paths).
 *   B. org.freedesktop.login1 (ListUsers/ListSessions + the Introspect+Properties
 *      RemoteHost reconstruction path) — other tenants' session roster + remote IP.
 *   C. /run/systemd/users/<UID> world-readable (0644) UID->username map.
 *
 * Design choice per channel:
 *   - login1 (B): a destination-WIDE deny for the default context, because no
 *     PMSS workflow calls login1 from a non-root context, and a member-only deny
 *     is bypassable (busctl tree -> get-property RemoteHost via the vendor's
 *     Introspectable + Properties allowances). Only self-PID lookups are re-allowed.
 *   - systemd1 (A): a METHOD-LEVEL deny-list, because the customer panel and PMSS
 *     tooling legitimately call systemd1 (GetUnit, GetUnitByPID, Properties
 *     Get/GetAll) to render unit status; a destination-wide deny would break them.
 *     Only the enumeration methods that leak other tenants' units and process
 *     command lines are denied. The cmdline leak rides exclusively on those denied
 *     methods; GetUnit + Properties expose only a unit's own object/properties
 *     (ControlGroup = the public UID, MainPID = hidepid-blocked), not other
 *     tenants' command lines.
 *   - /run/systemd/users (C): a tmpfiles.d drop-in tightening the per-UID files to
 *     0640. Lowest sensitivity (usernames are already public via /user-<name>/
 *     panel URLs), but trivially closeable.
 *
 * Root is never touched: the vendor policies keep their own <policy user="root">
 * stanzas, which dbus applies after (overriding) the default context.
 *
 * Drop-ins in /etc/dbus-1/system.d/ override the vendor copies in
 * /usr/share/dbus-1/system.d/ and survive package upgrades; managed files are
 * rewritten only when their content drifts, and dbus is reloaded only on change.
 *
 * REGRESSION NOTE: a malformed D-Bus policy makes dbus reject the file and can
 * break systemd/logind host-wide. The renders here are validated offline (XML
 * well-formedness) by the dev test; deployment is one-host-then-phased with a
 * tested rollback (remove drop-in + reload). NEVER fleet-push without the
 * one-host validation.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/processes.php';

/* -------------------------------------------------------------------------- *
 *  Channel B — login1 (session roster + remote IP)
 * -------------------------------------------------------------------------- */

/** Canonical basename for the managed logind enumeration-restriction drop-in. */
function pmssDbusLoginPolicyBasename(): string
{
    return '10-pmss-login1-restrict.conf';
}

/**
 * Manager methods that stay reachable for non-root callers (self-scoped).
 *
 * Both resolve the session/user of a *given PID*; with hidepid=2 a caller can
 * only meaningfully reference its own PIDs, so these answer "what is my own
 * session" (sd_pid_get_session and friends) without exposing other tenants'
 * rosters or remote addresses. Everything else on the destination is denied.
 */
function pmssDbusLoginSelfQueryMembers(): array
{
    return ['GetSessionByPID', 'GetUserByPID'];
}

/**
 * Render the managed login1 policy drop-in.
 *
 * For context="default" (every non-root local user) this denies the WHOLE
 * org.freedesktop.login1 destination, then re-allows only the self-scoped PID
 * lookups. A destination-wide deny is required because member-level denies on
 * the Manager interface are trivially bypassed: the base vendor policy allows
 * org.freedesktop.DBus.Introspectable and Properties.Get/GetAll to the default
 * context, so a user can `busctl tree` to harvest session object paths and then
 * `busctl get-property .../session/_3<id> ... RemoteHost` to read each remote
 * address — reconstructing the full roster without ever calling ListSessions.
 * Denying the destination closes Introspectable + Properties together.
 *
 * Root is untouched: the base policy keeps a separate <policy user="root">
 * stanza, and dbus applies user policies after (overriding) the default
 * context, so root tooling keeps full access.
 */
function pmssDbusLoginPolicyRender(): string
{
    $allows = '';
    foreach (pmssDbusLoginSelfQueryMembers() as $member) {
        $allows .= "                <allow send_destination=\"org.freedesktop.login1\"\n"
            ."                       send_interface=\"org.freedesktop.login1.Manager\"\n"
            ."                       send_member=\"".$member."\"/>\n";
    }

    return '<?xml version="1.0"?>'."\n"
        .'<!DOCTYPE busconfig PUBLIC "-//freedesktop//DTD D-BUS Bus Configuration 1.0//EN"'."\n"
        .'        "https://www.freedesktop.org/standards/dbus/1.0/busconfig.dtd">'."\n"
        .'<!-- PMSS-managed: restrict non-root logind access (privacy hardening).'."\n"
        .'     Denies the login1 destination for the default context (closes session/roster'."\n"
        .'     enumeration incl. the Introspect+Properties path bypass); self-PID lookups stay'."\n"
        .'     allowed; root is spared by its own stanza in the base policy.'."\n"
        .'     Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten. -->'."\n"
        .'<busconfig>'."\n"
        .'        <policy context="default">'."\n"
        .'                <deny send_destination="org.freedesktop.login1"/>'."\n"
        .$allows
        .'        </policy>'."\n"
        .'</busconfig>'."\n";
}

/* -------------------------------------------------------------------------- *
 *  Channel A — systemd1 (unit + process-tree / command-line enumeration)
 * -------------------------------------------------------------------------- */

/** Canonical basename for the managed systemd1 enumeration-restriction drop-in. */
function pmssDbusSystemd1PolicyBasename(): string
{
    return '10-pmss-systemd1-restrict.conf';
}

/**
 * systemd1 Manager methods denied for non-root callers.
 *
 * These enumerate other tenants' units AND their process trees — the process
 * variants return full command lines (rclone remote names, watch dirs,
 * rootless-docker state paths), which are NON-PUBLIC per-tenant config. hidepid=2
 * closes the /proc route; these D-Bus methods bypass it because systemd answers
 * as root. Self-scoped GetUnit/GetUnitByPID and Properties are deliberately NOT
 * in this list so the customer panel and PMSS tooling keep working.
 */
function pmssDbusSystemd1EnumerationMembers(): array
{
    return [
        'ListUnits', 'ListUnitsByPatterns', 'ListUnitsFiltered', 'ListUnitsByNames',
        'GetProcesses', 'GetUnitProcesses',
        'Dump', 'DumpByFileDescriptor',
    ];
}

/**
 * Render the managed systemd1 enumeration-restriction drop-in.
 *
 * METHOD-LEVEL deny-list (NOT a destination-wide deny — see module header): the
 * panel + tooling call systemd1 legitimately, so only the enumeration methods
 * are denied. The drop-in loads after the vendor policy in /usr/share, so these
 * denies override the vendor allow for the named members; the still-allowed
 * GetUnit/GetUnitByPID/Properties carry no other-tenant command-line content.
 *
 * One-host test MUST confirm: as non-root, ListUnits/GetProcesses/GetUnitProcesses
 * are denied AND no command-line reconstruction is possible via the allowed set,
 * while the panel still renders and root is unaffected.
 *
 * Root is untouched: the vendor policy keeps a <policy user="root"> stanza which
 * dbus applies after (overriding) the default context.
 */
function pmssDbusSystemd1PolicyRender(): string
{
    $denies = '';
    foreach (pmssDbusSystemd1EnumerationMembers() as $member) {
        $denies .= "                <deny send_destination=\"org.freedesktop.systemd1\"\n"
            ."                      send_interface=\"org.freedesktop.systemd1.Manager\"\n"
            ."                      send_member=\"".$member."\"/>\n";
    }

    return '<?xml version="1.0"?>'."\n"
        .'<!DOCTYPE busconfig PUBLIC "-//freedesktop//DTD D-BUS Bus Configuration 1.0//EN"'."\n"
        .'        "https://www.freedesktop.org/standards/dbus/1.0/busconfig.dtd">'."\n"
        .'<!-- PMSS-managed: restrict non-root systemd1 enumeration (privacy hardening).'."\n"
        .'     Denies the unit/process enumeration methods that leak other tenants units and'."\n"
        .'     command lines; self-scoped GetUnit/GetUnitByPID and Properties stay allowed so the'."\n"
        .'     customer panel and PMSS tooling keep working; root is spared by its own stanza.'."\n"
        .'     Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten. -->'."\n"
        .'<busconfig>'."\n"
        .'        <policy context="default">'."\n"
        .$denies
        .'        </policy>'."\n"
        .'</busconfig>'."\n";
}

/* -------------------------------------------------------------------------- *
 *  Shared installer for managed D-Bus policy drop-ins
 * -------------------------------------------------------------------------- */

/**
 * Install one managed D-Bus policy drop-in idempotently and reload dbus on change.
 *
 * Shared by every channel so the install/reload behaviour (and its env override
 * PMSS_DBUS_SYSTEM_POLICY_DIR for hermetic tests) stays in one place. The $label
 * appears verbatim in the SKIP / Installed log lines.
 */
function pmssEnsureDbusManagedPolicy(string $basename, string $content, string $label, ?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $policyDir = pmssResolvePathFromEnv('PMSS_DBUS_SYSTEM_POLICY_DIR', '/etc/dbus-1/system.d');
    $target = $policyDir.'/'.$basename;

    $existing = @file_get_contents($target);
    if ($existing !== false && $existing === $content) {
        $log('[SKIP] '.$label.' already present and up to date');
        return;
    }

    if (!pmssDirEnsureExists($policyDir, 0755)) {
        $log('[WARN] Unable to create D-Bus system policy directory: '.$policyDir);
        return;
    }

    if (!pmssWriteManagedPathFile(
        $target,
        $content,
        $label,
        $log,
        null,
        null,
        0644,
        '[WARN] Unable to install '.$label.' at '.$target
    )) {
        return;
    }
    $log('Installed '.$label.' at '.$target);

    if (($skipReason = pmssSystemdActionSkipReason('dbus.service', true, true)) !== '') {
        pmssLogStatus('SKIP', 'Reloading dbus for '.$label.' ('.$skipReason.')');
        return;
    }

    runStep('Reloading dbus to apply '.$label, 'systemctl reload dbus');
}

/**
 * Ensure the PMSS logind enumeration-restriction D-Bus drop-in is installed (B).
 */
function pmssEnsureDbusLoginEnumerationPolicy(?callable $logger = null): void
{
    pmssEnsureDbusManagedPolicy(
        pmssDbusLoginPolicyBasename(),
        pmssDbusLoginPolicyRender(),
        'logind D-Bus enumeration policy',
        $logger
    );
}

/**
 * Ensure the PMSS systemd1 enumeration-restriction D-Bus drop-in is installed (A).
 */
function pmssEnsureDbusSystemd1EnumerationPolicy(?callable $logger = null): void
{
    pmssEnsureDbusManagedPolicy(
        pmssDbusSystemd1PolicyBasename(),
        pmssDbusSystemd1PolicyRender(),
        'systemd1 D-Bus enumeration policy',
        $logger
    );
}

/* -------------------------------------------------------------------------- *
 *  Channel C — /run/systemd/users/<UID> world-readable UID->username map
 * -------------------------------------------------------------------------- */

/** Canonical basename for the /run/systemd/users mode-restriction tmpfiles drop-in. */
function pmssRunSystemdUsersTmpfilesBasename(): string
{
    return 'pmss-run-systemd-users.conf';
}

/**
 * Render the tmpfiles.d drop-in restricting the /run/systemd/users DIRECTORY to 0750.
 *
 * logind creates the per-UID files 0644 (world-readable UID->username map) and
 * RE-creates them on each login, so a per-file `z 0640` rule does not hold between
 * tmpfiles runs. Restricting the PARENT DIRECTORY to 0750 instead is robust: the
 * directory is created once by logind at boot and is NOT recreated per-login, so a
 * non-root user simply cannot traverse into it to read any per-UID file regardless
 * of the files' own modes. tmpfiles `d` sets+re-asserts the directory mode at boot
 * and on `systemd-tmpfiles --create`. loginctl reaches this data over the login1
 * D-Bus (restricted separately, Channel B), not the filesystem, so the 0750 dir
 * does not break it; nothing in PMSS reads /run/systemd/users as non-root.
 */
function pmssRunSystemdUsersTmpfilesRender(): string
{
    return "# PMSS-managed: restrict the /run/systemd/users directory (UID->username map) to root.\n"
        ."# Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten.\n"
        ."d /run/systemd/users 0750 root root -\n";
}

/**
 * Ensure the /run/systemd/users tmpfiles mode-restriction drop-in is installed (C).
 *
 * Mirrors the hermetic-test pattern (PMSS_TMPFILES_DIR override, PMSS_TEST_MODE
 * guards the systemd-tmpfiles apply) used elsewhere in system-prep.
 */
function pmssEnsureRunSystemdUsersTmpfiles(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $dir = pmssResolvePathFromEnv('PMSS_TMPFILES_DIR', '/etc/tmpfiles.d');
    $target = $dir.'/'.pmssRunSystemdUsersTmpfilesBasename();
    $content = pmssRunSystemdUsersTmpfilesRender();

    $existing = @file_get_contents($target);
    if ($existing !== false && $existing === $content) {
        $log('[SKIP] /run/systemd/users tmpfiles policy already present and up to date');
        return;
    }

    if (!pmssDirEnsureExists($dir, 0755)) {
        $log('[WARN] Unable to create tmpfiles.d directory: '.$dir);
        return;
    }

    if (!pmssWriteManagedPathFile(
        $target,
        $content,
        '/run/systemd/users tmpfiles policy',
        $log,
        null,
        null,
        0644,
        '[WARN] Unable to install /run/systemd/users tmpfiles policy at '.$target
    )) {
        return;
    }
    $log('Installed /run/systemd/users tmpfiles policy at '.$target);

    if (function_exists('pmssTestModeEnabled') && pmssTestModeEnabled()) {
        pmssLogStatus('SKIP', 'Applying /run/systemd/users tmpfiles policy (test mode)');
        return;
    }

    // tmpfiles `d` re-asserts the directory mode at boot; also chmod the already-existing
    // directory now so the restriction takes effect immediately on this update run.
    runStep(
        'Restricting /run/systemd/users directory mode',
        'systemd-tmpfiles --create '.escapeshellarg($target).' 2>/dev/null; [ -d /run/systemd/users ] && chmod 0750 /run/systemd/users 2>/dev/null || true'
    );
}

/* -------------------------------------------------------------------------- *
 *  Orchestrator — all three channels
 * -------------------------------------------------------------------------- */

/**
 * Ensure all three systemd cross-user disclosure channels are hardened.
 *
 * Single entry point wired from update-step2; each sub-step is independently
 * idempotent and degrades to a [WARN]/[SKIP] without aborting the others.
 */
function pmssEnsureSystemdDbusDisclosureHardening(?callable $logger = null): void
{
    pmssEnsureDbusSystemd1EnumerationPolicy($logger); // A — worst
    pmssEnsureDbusLoginEnumerationPolicy($logger);    // B
    pmssEnsureRunSystemdUsersTmpfiles($logger);       // C — lowest sensitivity
}
