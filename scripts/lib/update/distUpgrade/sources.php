<?php
/**
 * Dist-upgrade apt source rewriting helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssRewriteSources(string $fromMajor, string $toMajor): void
{
    $from = pmssDistUpgradeIsAllowedMajor($fromMajor) ? pmssDebianCodenameFromMajor((int) $fromMajor) : '';
    $to = pmssDistUpgradeIsAllowedMajor($toMajor) ? pmssDebianCodenameFromMajor((int) $toMajor) : '';
    if ($from === '' || $to === '') {
        logMessage('Unable to resolve codenames for upgrade path');
        return;
    }

    // The target release's own template names the mirrors that still serve it (bullseye-security
    // packages now live only on archive.debian.org), so it replaces sources.list when one ships.
    // The rewrite below then covers only the lists the template does not own (#1119).
    $paths = ['/etc/apt/sources.list', '/etc/apt/sources.list.d/*.list'];
    $template = pmssLoadRepoTemplate($to);
    if ($template !== '' && pmssSafeWriteSources($template, ucfirst($to).' (dist-upgrade)')) {
        logMessage(sprintf('Applied the %s apt template before the upgrade', $to));
        $paths = ['/etc/apt/sources.list.d/*.list'];
    }
    $sedExpressions = [
        sprintf("s/\\<%s\\>/%s/g", $from, $to),
        sprintf("s#%s/updates#%s-security#g", $to, $to),
    ];
    foreach ($paths as $path) {
        foreach ($sedExpressions as $expr) {
            runCommand("sed -i '{$expr}' {$path}");
        }
    }

    static $rewritePairs = [
        "sed -i -E 's@https?://archive\\.debian\\.org/debian-security@http://security.debian.org/debian-security@g' %s",
        "sed -i -E 's@https?://archive\\.debian\\.org/debian@http://deb.debian.org/debian@g' %s",
    ];
    foreach ($rewritePairs as $cmdFormat) {
        foreach ($paths as $path) {
            runCommand(sprintf($cmdFormat, $path));
        }
    }
}
