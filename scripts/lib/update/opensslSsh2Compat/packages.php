<?php
/** OpenSSL/OpenSSH compatibility package queries and command formatting. @license GPL-3.0-only */

function pmssOpenSslSsh2CompatVersionQueryCommand(string $package): string
{
    return 'dpkg-query -W -f='.escapeshellarg('${Version}').' '.escapeshellarg($package).' 2>/dev/null';
}

// Use dpkg for version ordering so epoch/suffix comparisons stay correct.
function pmssOpenSslSsh2CompatVersionCompare(string $left, string $operator, string $right): bool
{
    $rc = 1;
    @exec(
        'dpkg --compare-versions '
        .escapeshellarg($left).' '
        .escapeshellarg($operator).' '
        .escapeshellarg($right),
        $unused,
        $rc
    );

    return $rc === 0;
}

function pmssOpenSslSsh2CompatPackageHeld(string $heldList, string $package): bool
{
    return preg_match('/(^|\R)'.preg_quote($package, '/').'($|\R)/', $heldList) === 1;
}

// Return space-separated package=version specs for apt commands.
function pmssOpenSslSsh2CompatPackageVersionSpecs(array $packages, string $version, bool $escapeVersion = false): string
{
    return implode(' ', array_map(static function (string $package) use ($version, $escapeVersion): string {
        return $package.'='.($escapeVersion ? escapeshellarg($version) : $version);
    }, $packages));
}

function pmssOpenSslSsh2CompatOpenSshPackages(?string $version = null, bool $escapeVersion = false): string
{
    return $version === null
        ? implode(' ', PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES)
        : pmssOpenSslSsh2CompatPackageVersionSpecs(PMSS_OPENSSL_SSH2_OPENSSH_PACKAGES, $version, $escapeVersion);
}

function pmssOpenSslSsh2CompatDownloadedDebs(string $tmpDir): array
{
    $debs = glob($tmpDir.'/*.deb') ?: [];
    sort($debs);

    return $debs;
}

function pmssOpenSslSsh2CompatDebsByPrefix(array $debs, string $prefix): array
{
    return array_values(array_filter($debs, static function (string $deb) use ($prefix): bool {
        return strpos(basename($deb), $prefix) === 0;
    }));
}

function pmssOpenSslSsh2CompatDpkgInstallCommand(array $debs): string
{
    return dpkgCmd('--force-confdef --force-confold -i '.implode(' ', array_map('escapeshellarg', $debs)));
}

