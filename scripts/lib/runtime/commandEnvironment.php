<?php
/** Shell invocation and unattended package-command environment. */

function pmssCommandIsAptDpkg(string $cmd): bool
{
    return preg_match('/\b(apt-get|apt|dpkg)\b/i', $cmd) === 1;
}

/**
 * Return the environment every apt/dpkg command must inherit.
 *
 * Detached update runs cannot answer debconf, ucf, listchanges, or needrestart
 * prompts. Keep the variables in one place so direct dpkg recovery paths and
 * apt wrappers do not drift apart.
 *
 * @return array<string, string>
 */
function pmssAptDpkgEnvAssignments(array $overrides = []): array
{
    $assignments = [
        'DEBIAN_FRONTEND' => 'noninteractive',
        'APT_LISTCHANGES_FRONTEND' => 'none',
        'UCF_FORCE_CONFDEF' => '1',
        'UCF_FORCE_CONFOLD' => '1',
        'NEEDRESTART_MODE' => 'a',
    ];

    foreach ($overrides as $key => $value) {
        if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_float($value))) {
            continue;
        }
        $value = (string) $value;
        if (pmssAptDpkgEnvAssignmentIsSafe($key, $value)) {
            $assignments[$key] = $value;
        }
    }

    return $assignments;
}

/** Keep generated shell prefixes to simple KEY=value environment assignments. */
function pmssAptDpkgEnvAssignmentIsSafe(string $key, string $value): bool
{
    return preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1
        && preg_match('/^[A-Za-z0-9_@%+=:,.\/-]*$/', $value) === 1;
}

function pmssAptDpkgEnvPrefix(array $overrides = []): string
{
    $parts = [];
    foreach (pmssAptDpkgEnvAssignments($overrides) as $key => $value) {
        $parts[] = $key.'='.$value;
    }

    return implode(' ', $parts);
}

function pmssAptDpkgEnvExportPrefix(?string $pathOverride = null, array $overrides = []): string
{
    $pathPrefix = $pathOverride !== null && $pathOverride !== '' ? 'PATH='.escapeshellarg($pathOverride).' ' : '';
    return 'export '.$pathPrefix.pmssAptDpkgEnvPrefix($overrides).'; ';
}

function pmssCommandBashInvocation(string $cmd): string
{
    $cmdForShell = $cmd;
    $isAptDpkg = pmssCommandIsAptDpkg($cmd);
    if ($isAptDpkg && preg_match('/[|;]|&&|\|\|/', $cmd) !== 1) {
        $cmdForShell = preg_match('/^(\s*(?:[A-Za-z_][A-Za-z0-9_]*=\S+\s+)+)(.+)$/', $cmd, $match) === 1
            ? rtrim($match[1]).' exec '.ltrim($match[2])
            : 'exec '.$cmd;
    }

    $pathOverride = getenv('PATH');
    $pathShouldApply = $pathOverride !== false && $pathOverride !== '' && preg_match('/(^|\s)PATH=/', $cmdForShell) !== 1;
    if ($isAptDpkg) {
        $cmdForShell = pmssAptDpkgEnvExportPrefix($pathShouldApply ? $pathOverride : null).$cmdForShell;
    } elseif ($pathShouldApply) {
        $cmdForShell = 'PATH='.escapeshellarg($pathOverride).' '.$cmdForShell;
    }

    $cmdForShell = pmssLockChildClosePrefix().$cmdForShell;

    return '/bin/bash -lc '.escapeshellarg($cmdForShell);
}
