<?php
/** Aggregate home probe construction and pure result interpretation for transfers. */
/** @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/remoteScripts.php';

/**
 * Build a privacy-safe shell fragment that prints aggregate home statistics.
 */
function pmssUserTransferBuildHomeStatsShell(string $home): string
{
    $home = rtrim($home, '/').'/';
    $lines = [
        // Partial scans must fail even when a later pipeline stage succeeds.
        'set -o pipefail',
        'bytes=$(du -sbx -- "$home" 2>/dev/null | awk "NR == 1 {print \$1}") || exit 1',
        'files=$(find "$home" -xdev -type f -printf . 2>/dev/null | wc -c | tr -d "[:space:]") || exit 1',
        'case "$bytes" in ""|*[!0-9]*) exit 1 ;; esac',
        'case "$files" in ""|*[!0-9]*) exit 1 ;; esac',
        'printf "bytes=%s files=%s\n" "$bytes" "$files"',
        // Resolve the home's device from its longest containing mount. Never emit paths.
        <<<'SHELL'
awk -v home="$home" '
    $5 == "/" || index(home, $5 "/") == 1 {
        if (length($5) > longest) { longest = length($5); device = $3 }
    }
    index($5, home) == 1 {
        for (i = 7; i < NF; i++) if ($i == "-") {
            devices[++n] = $3; types[n] = $(i + 1); break
        }
    }
    END {
        if (device == "") exit 1
        for (i = 1; i <= n; i++) if (devices[i] != device) {
            count++
            if (types[i] ~ /^[a-zA-Z0-9_.-]+$/) totals[types[i]]++
        }
        printf "nested_mounts=%d", count
        for (type in totals) printf " mount_type_%s=%d", type, totals[type]
        printf "\n"
    }
' /proc/self/mountinfo 2>/dev/null || printf 'nested_mounts=unavailable\n'
SHELL
    ];

    return 'home='.escapeshellarg($home)."\n".implode("\n", $lines)."\n";
}

/**
 * Build a remote size/count probe script that emits aggregate values only.
 */
function pmssUserTransferBuildRemoteSizeProbe(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'].'/';
    $remoteCommand = pmssBuildCommand('bash', ['-c', pmssUserTransferBuildHomeStatsShell($remoteHome)]);

    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand($cfg, $remoteCommand)."\n";
}

/**
 * Parse the byte count from legacy `du -sb` or aggregate stats output.
 */
function pmssUserTransferParseDuBytes(string $output): ?int
{
    if (preg_match('/\bbytes=([0-9]+)\b/', $output, $matches) === 1) {
        return pmssUnsignedDecimalIntParse($matches[1]);
    }

    foreach (pmssTextLines(trim($output)) as $line) {
        if (preg_match('/^\s*([0-9]+)\b/', $line, $matches) === 1) {
            return pmssUnsignedDecimalIntParse($matches[1]);
        }
    }

    return null;
}

/**
 * Parse aggregate home statistics from the privacy-safe probe output.
 */
function pmssUserTransferParseHomeStats(string $output): ?array
{
    if (preg_match('/\bbytes=([0-9]+)\s+files=([0-9]+)\b/', trim($output), $matches) !== 1) {
        return null;
    }

    // Reject unrepresentable command counters before PHP can saturate the cast.
    $bytes = pmssUnsignedDecimalIntParse($matches[1]);
    $files = pmssUnsignedDecimalIntParse($matches[2]);
    return $bytes === null || $files === null ? null : ['bytes' => $bytes, 'files' => $files];
}

/**
 * Compare remote/local sizes and return advisory details when the local copy is short.
 */
function pmssUserTransferEvaluateCompleteness(int $remoteBytes, int $localBytes, int $verifyThreshold): ?array
{
    if ($remoteBytes <= 0) {
        return null;
    }

    $localPercent = ($localBytes / $remoteBytes) * 100;
    if ($localPercent >= $verifyThreshold) {
        return null;
    }

    return [
        'remoteBytes' => $remoteBytes,
        'localBytes' => $localBytes,
        'verifyThreshold' => $verifyThreshold,
        'localPercent' => round($localPercent, 2),
    ];
}
