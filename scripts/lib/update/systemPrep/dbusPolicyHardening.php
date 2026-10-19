<?php
/**
 * D-Bus / runtime system policy hardening for update-step2 system preparation.
 *
 * systemd exposes several cross-user disclosure channels on shared hosts:
 * systemd1 unit/process enumeration, login1 session enumeration, and the
 * world-readable runtime metadata below /run/systemd/{users,sessions}.
 * The artifact registry below keeps the four protections in one ordered model
 * so rendering, installation, and orchestration cannot drift independently.
 *
 * Root remains unaffected by the D-Bus rules: vendor user="root" policies are
 * applied after the default context. The systemd1 policy denies only known
 * enumeration members because customer tooling needs self-scoped unit calls;
 * login1 is destination-denied with only self-PID queries re-allowed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/processes.php';

/**
 * Return the complete ordered set of disclosure-hardening artifacts.
 *
 * Artifact content is generated solely from this registry. Keeping filenames,
 * labels, policy members, and tmpfiles paths together eliminates the former
 * per-channel basename/render/install function families.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssSystemdDbusDisclosureArtifacts(): array
{
    $systemd1Denies = '';
    foreach ([
        'ListUnits', 'ListUnitsByPatterns', 'ListUnitsFiltered', 'ListUnitsByNames',
        'GetProcesses', 'GetUnitProcesses', 'Dump', 'DumpByFileDescriptor',
    ] as $member) {
        $systemd1Denies .= "                <deny send_destination=\"org.freedesktop.systemd1\"\n"
            ."                      send_interface=\"org.freedesktop.systemd1.Manager\"\n"
            ."                      send_member=\"".$member."\"/>\n";
    }

    $login1Allows = '';
    foreach (['GetSessionByPID', 'GetUserByPID'] as $member) {
        $login1Allows .= "                <allow send_destination=\"org.freedesktop.login1\"\n"
            ."                       send_interface=\"org.freedesktop.login1.Manager\"\n"
            ."                       send_member=\"".$member."\"/>\n";
    }

    $xmlHeader = '<?xml version="1.0"?>'."\n"
        .'<!DOCTYPE busconfig PUBLIC "-//freedesktop//DTD D-BUS Bus Configuration 1.0//EN"'."\n"
        .'        "https://www.freedesktop.org/standards/dbus/1.0/busconfig.dtd">'."\n";

    return [
        [
            'kind' => 'dbus',
            'basename' => '10-pmss-systemd1-restrict.conf',
            'label' => 'systemd1 D-Bus enumeration policy',
            'content' => $xmlHeader
                .'<!-- PMSS-managed: restrict non-root systemd1 enumeration (privacy hardening).'."\n"
                .'     Denies the unit/process enumeration methods that leak other tenants units and'."\n"
                .'     command lines; self-scoped GetUnit/GetUnitByPID and Properties stay allowed so the'."\n"
                .'     customer panel and PMSS tooling keep working; root is spared by its own stanza.'."\n"
                .'     Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten. -->'."\n"
                .'<busconfig>'."\n"
                .'        <policy context="default">'."\n"
                .$systemd1Denies
                .'        </policy>'."\n"
                .'</busconfig>'."\n",
        ],
        [
            'kind' => 'dbus',
            'basename' => '10-pmss-login1-restrict.conf',
            'label' => 'logind D-Bus enumeration policy',
            'content' => $xmlHeader
                .'<!-- PMSS-managed: restrict non-root logind access (privacy hardening).'."\n"
                .'     Denies the login1 destination for the default context (closes session/roster'."\n"
                .'     enumeration incl. the Introspect+Properties path bypass); self-PID lookups stay'."\n"
                .'     allowed; root is spared by its own stanza in the base policy.'."\n"
                .'     Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten. -->'."\n"
                .'<busconfig>'."\n"
                .'        <policy context="default">'."\n"
                .'                <deny send_destination="org.freedesktop.login1"/>'."\n"
                .$login1Allows
                .'        </policy>'."\n"
                .'</busconfig>'."\n",
        ],
        [
            'kind' => 'tmpfiles',
            'basename' => 'pmss-run-systemd-users.conf',
            'label' => '/run/systemd/users tmpfiles policy',
            'runtime_path' => '/run/systemd/users',
            'description' => 'Restricting /run/systemd/users directory mode',
            'content' => "# PMSS-managed: restrict the /run/systemd/users directory (UID->username map) to root.\n"
                ."# Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten.\n"
                ."d /run/systemd/users 0750 root root -\n",
        ],
        [
            'kind' => 'tmpfiles',
            'basename' => 'pmss-run-systemd-sessions.conf',
            'label' => '/run/systemd/sessions tmpfiles policy',
            'runtime_path' => '/run/systemd/sessions',
            'description' => 'Restricting /run/systemd/sessions directory mode',
            'content' => "# PMSS-managed: restrict the /run/systemd/sessions directory (per-session REMOTE_HOST/client IP) to root.\n"
                ."# Managed by scripts/lib/update/systemPrep/dbusPolicyHardening.php; edits are overwritten.\n"
                ."d /run/systemd/sessions 0750 root root -\n",
        ],
    ];
}

/**
 * Ensure all four systemd cross-user disclosure channels are hardened.
 *
 * Each artifact converges independently and degrades to a warning or skip, so
 * one unavailable policy location does not prevent the remaining protections.
 */
function pmssEnsureSystemdDbusDisclosureHardening(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    foreach (pmssSystemdDbusDisclosureArtifacts() as $artifact) {
        if ($artifact['kind'] === 'dbus') {
            $dir = pmssResolvePathFromEnv('PMSS_DBUS_SYSTEM_POLICY_DIR', '/etc/dbus-1/system.d');
            $directoryFailure = '[WARN] Unable to create D-Bus system policy directory: '.$dir;
        } else {
            $dir = pmssResolvePathFromEnv('PMSS_TMPFILES_DIR', '/etc/tmpfiles.d');
            $directoryFailure = '[WARN] Unable to create tmpfiles.d directory: '.$dir;
        }

        $target = $dir.'/'.$artifact['basename'];
        $options = pmssManagedPathInstallOptions($target, $artifact['label'], [
            'directoryFailureMessage' => $directoryFailure,
        ]);
        if (!pmssRefreshManagedPathFile($target, $artifact['content'], $artifact['label'], $log, $options)) {
            continue;
        }

        if ($artifact['kind'] === 'dbus') {
            if (!pmssSystemdActionSkip(pmssSystemdActionSkipReason('dbus.service', true, true), 'Reloading dbus for '.$artifact['label'])) {
                runStep('Reloading dbus to apply '.$artifact['label'], 'systemctl reload dbus');
            }
            continue;
        }

        if (function_exists('pmssTestModeEnabled') && pmssTestModeEnabled()) {
            pmssLogStatus('SKIP', 'Applying '.$artifact['label'].' (test mode)');
            continue;
        }

        // The tmpfiles rule persists the directory mode; chmod applies it immediately.
        runStep(
            $artifact['description'],
            'systemd-tmpfiles --create '.escapeshellarg($target).' 2>/dev/null; [ -d '.$artifact['runtime_path'].' ] && chmod 0750 '.$artifact['runtime_path'].' 2>/dev/null || true'
        );
    }
}
