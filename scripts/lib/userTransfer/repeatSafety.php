<?php
/**
 * Refuse destructive pulls over an already completed or newer target account.
 * Completion checks read the operator-only store, never the customer home cache.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/user/userConfigStore.php';

/** Read durable state; unavailable or malformed history cannot authorize a pull. */
function pmssUserTransferCompletionPayload(array $cfg, UserConfigStore $store): array
{
    $payload = $store->get($cfg['localUser']);
    if ($payload === null || (array_key_exists('userTransferCompleted', $payload)
        && !is_array($payload['userTransferCompleted']))) {
        throw new RuntimeException('Cannot read transfer completion history; refusing transfer', 1);
    }
    return $payload;
}

/** Keep host case equivalent and distinguish source accounts on the same host. */
function pmssUserTransferSourceKey(array $cfg): string
{
    return strtolower($cfg['hostname']).'/'.$cfg['remoteUser'];
}

/** Check before authentication, setup, or any rsync pass; recovery never replaces files. */
function pmssUserTransferAssertNotRepeated(array $cfg, UserConfigStore $store): void
{
    if (isset($cfg['into'])) return;
    $payload = pmssUserTransferCompletionPayload($cfg, $store);
    if (array_key_exists(pmssUserTransferSourceKey($cfg), $payload['userTransferCompleted'] ?? [])
        && empty($cfg['force'])) {
        throw new RuntimeException('Transfer from this source already completed; use --into for recovery or --force to overwrite', 1);
    }
}

/** Save only after successful migration; preserve history for every earlier source. */
function pmssUserTransferRecordCompletion(array $cfg, UserConfigStore $store): void
{
    if (isset($cfg['into'])) return;
    $payload = pmssUserTransferCompletionPayload($cfg, $store);
    $payload['userTransferCompleted'][pmssUserTransferSourceKey($cfg)] = gmdate('c');
    if (!$store->set($cfg['localUser'], $payload)) {
        throw new RuntimeException('Cannot save transfer completion history', 1);
    }
}

/** Emit only the newest regular-file mtime; never follow links or cross filesystems. */
function pmssUserTransferBuildNewestFileShell(string $home): string
{
    // Compare padded decimal strings to retain nanoseconds in constant memory.
    // No sort scratch files: the receiver's outside mounts are read-only.
    $maximum = <<<'AWK'
awk '
    BEGIN { newest = "0"; maximum = "" }
    {
        split($0, part, ".")
        if ($0 !~ /^[0-9]+\.[0-9]+$/ || length(part[1]) > 20 || length(part[2]) > 10) exit 1
        key = substr("00000000000000000000" part[1], length(part[1]) + 1) substr(part[2] "0000000000", 1, 10)
        if ("x" key > "x" maximum) { maximum = key; newest = $0 }
    }
    END { print "PMSS_TRANSFER_NEWEST=" newest }
'
AWK;
    return "set -o pipefail\ncd / || exit 1\n"
        .'newest=$(LC_ALL=C find -P '.escapeshellarg(rtrim($home, '/').'/')
        ." -xdev -type f -printf '%T@\\n' 2>/dev/null | LC_ALL=C ".$maximum.") || exit 1\n"
        .'printf "%s\\n" "$newest"'."\n";
}

/** Parse exactly one aggregate token, retaining nanoseconds without float rounding. */
function pmssUserTransferNewestFileTime(string $output): array
{
    if (preg_match_all('/^PMSS_TRANSFER_NEWEST=([0-9]+)(?:\.([0-9]{1,10}))?\r?$/m', $output, $matches, PREG_SET_ORDER) !== 1
        || ($seconds = pmssUnsignedDecimalIntParse($matches[0][1])) === null) {
        throw new RuntimeException('Cannot establish newest file timestamp; refusing transfer', 1);
    }
    return [$seconds, (int) str_pad($matches[0][2] ?? '', 10, '0')];
}

/** Fail closed on scan errors or newer target files before the first data pull. */
function pmssUserTransferAssertSourceNotOlder(array $cfg, string $home, array $paths, ?callable $runner = null): void
{
    if (isset($cfg['into']) || !empty($cfg['force'])) return;
    $runner = $runner ?? 'runStep';
    $remote = pmssBuildCommand('bash', ['-c', pmssUserTransferBuildNewestFileShell('/home/'.$cfg['remoteUser'])]);
    if (!empty($cfg['namespaceRsync'])) {
        $remote = 'unshare --map-auto --map-root-user '.$remote;
    }
    pmssUserTransferWriteFile($paths['newestProbe'], "#!/bin/bash\nset -e\n"
        .pmssUserTransferBuildSshProbeCommand($cfg, $remote)."\n", 0700);
    $commands = [
        'source' => pmssBuildCommand($paths['expect'], [$paths['newestProbe']]),
        'target' => pmssUserTransferBuildReceiver($cfg,
            pmssBuildCommand('bash', ['-c', pmssUserTransferBuildNewestFileShell($home)])),
    ];
    $times = [];
    foreach ($commands as $side => $command) {
        if ($runner('Reading newest '.$side.' file timestamp', $command) !== 0) {
            throw new RuntimeException('Cannot scan '.$side.' file timestamps; refusing transfer', 1);
        }
        $times[$side] = pmssUserTransferNewestFileTime((string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''));
    }
    if ($times['target'] > $times['source']) {
        throw new RuntimeException('Target home has files newer than the source; use --into for recovery or --force to overwrite', 1);
    }
}
