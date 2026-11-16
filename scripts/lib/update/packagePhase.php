<?php
/** Ordered phase-2 package convergence; called after the existing repository prelude.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Preserve package commands, profiling and failure boundaries under the phase-2 lock. */
function pmssUpdateStep2PackagePhase(array $distribution, array $dockerVersionsBefore): void
{
    runStep('Attempting apt fix-broken install (pre-package phase)', aptCmd('--fix-broken install -y'));
    pmssRunProfiledCallable('Refreshing package repositories', 'pmssRefreshRepositories', [$distribution['name'], $distribution['repoVersion'], 'logmsg']);
    $dpkgBaselineOk = pmssRunProfiledCallable('Applying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [$distribution['baselineVersion'], true]);

    // System-wide services must not run on seedbox hosts. Stop/disable early so
    // package installs cannot leave attack surface exposed for the rest of the run.
    pmssRunProfiledCallableBatch([
        ['Applying system service disable/mask policy (pre-app)', 'pmssStopDisableMaskSeedboxSystemServices'],
        // Purge failed unbound and keep disabled services from returning at boot.
        ['Purging failed unbound daemon if present', 'pmssPurgeFailedUnbound'],
        ['Ensuring systemd service guard boot unit', 'pmssEnsureSystemdServicesGuardBootUnit'],
    ]);
    // Remove legacy Apache packages; keep apache2-utils. It provides htpasswd (used by
    // lighttpd basic auth) and ab; removing it breaks auth setup and other scripts.
    runStep('Removing residual Apache packages', aptCmd('purge -y apache2 apache2-bin apache2-data libapache2-mod-php7.4 || true'));
    pmssRunProfiledCallable('Hardening legacy Apache systemd unit (post-purge)', 'pmssStopDisableMaskSystemdUnit', ['apache2', 'Apache httpd (legacy)', true]);
    if ($distribution['repoMessage'] !== '') { logmsg($distribution['repoMessage']); }
    if (!$dpkgBaselineOk) {
        logmsg('[WARN] Dpkg baseline application reported issues; attempting recovery');
        $dpkgBaselineOk = pmssRecoverDpkgBaseline('Attempting apt fix-broken install (dpkg baseline recovery)')
            && pmssRunProfiledCallable('Reapplying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [$distribution['baselineVersion'], true]);
        if (!$dpkgBaselineOk) {
            logmsg('[ERROR] Dpkg baseline still failing after recovery attempt; continuing with caution');
            pmssLogJson(['event' => 'package_phase', 'status' => 'warn', 'reason' => 'dpkg_baseline']);
        }
    }

    // Re-apply libssl3 + openssl hold after dpkg baseline phase.
    // Belt-2: selections-debian12 hold entries are belt-1. This call handles
    // hosts already at broken versions and ensures convergence to exactly 3.0.17.
    // Uses simulate-guarded downgrade logic with a dpkg-direct fallback path when
    // apt predicts openssh removals. Refs #436.
    $opensslCompatArgs = [$distribution['baselineVersion']];
    pmssRunProfiledCallableBatch([
        ['Holding libssl3/openssl for PECL ssh2 compat (dpkg-direct guard)', 'pmssHoldLibssl3ForPeclSsh2Compat', $opensslCompatArgs],
        ['Healing openssh-server post-cascade if missing/deleted', 'pmssHealOpensshServerIfMissing', $opensslCompatArgs],
        ['Ensuring openssh-server is libssl3-3.0.17-compatible (canonical baseline downgrade)', 'pmssEnsureOpensshCompatibleWithHeldLibssl3', $opensslCompatArgs],
    ]);

    // Package convergence: dpkg selections are the authoritative source of package
    // state.
    logmsg('[OK] Package phase relies on dpkg baseline selections only');

    pmssRecoverDpkgBaseline('Attempting apt fix-broken install (post-package phase)');
    runStep('Removing packages no longer required', aptCmd('autoremove -y'));

    $dockerVersions = ['changed' => false, 'before' => $dockerVersionsBefore, 'after' => pmssDockerPackageVersions()];
    $dockerVersions['changed'] = pmssDockerPackageVersionsChanged($dockerVersions['before'], $dockerVersions['after']);
    putenv('PMSS_DOCKER_PACKAGES_CHANGED='.($dockerVersions['changed'] ? '1' : '0'));
    pmssLogJson(['event' => 'docker_package_versions'] + $dockerVersions);
    if ($dockerVersions['changed']) {
        logmsg('[INFO] Docker runtime package versions changed; rootless daemons will restart during user maintenance');
    }

    $GLOBALS['PMSS_PACKAGES_READY'] = true;
    putenv('PMSS_PACKAGE_PHASE=complete');
    pmssLogJson(['event' => 'package_phase', 'status' => 'ok']);
}
