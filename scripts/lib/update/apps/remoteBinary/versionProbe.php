<?php
/** Installed-version trust checks and bounded probes; loaded by remoteBinary.php. */

const PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS = 150;

/** Return true only for paths reserved for system-managed executables. */
function pmssAppVersionProbeSystemPathIsSafe(string $path): bool
{
    foreach (['/bin', '/sbin', '/usr/bin', '/usr/sbin', '/usr/lib', '/usr/libexec', '/usr/local', '/opt'] as $prefix) {
        if ($path === $prefix || strpos($path, $prefix.'/') === 0) {
            return true;
        }
    }

    return false;
}

/** Accept root and Debian-style system UIDs, but never ordinary user accounts. */
function pmssAppVersionProbeSystemUidIsTrusted($uid): bool
{
    return is_int($uid) && $uid >= 0 && $uid < 1000;
}

/** Reject system paths whose directory chain could be replaced by a user. */
function pmssAppVersionProbeParentChainIsTrusted(string $path): bool
{
    for ($directory = dirname($path); $directory !== '/' && $directory !== '.'; $directory = dirname($directory)) {
        $stat = @stat($directory);
        if (!is_array($stat)
            || !pmssAppVersionProbeSystemUidIsTrusted($stat['uid'] ?? null)
            || (((int) ($stat['mode'] ?? 0) & 0022) !== 0)
        ) {
            return false;
        }
    }

    return true;
}

/** Extract the first argv token from the command forms used by app probes. */
function pmssAppVersionProbeLeadingToken(string $command): ?string
{
    $command = ltrim($command);
    if ($command === '' || preg_match('/[\x00\r\n]/', $command) === 1) {
        return null;
    }

    if ($command[0] === "'") {
        return preg_match("/\\A'([^'\\r\\n\\0]+)'(?:\\s|\\z)/", $command, $match) === 1
            ? $match[1]
            : null;
    }

    return preg_match('/\\A([A-Za-z0-9_+.\\/-]+)(?:\\s|\\z)/', $command, $match) === 1
        ? $match[1]
        : null;
}

/** Resolve and validate the binary before any installed app probe is launched. */
function pmssAppVersionProbeBinaryIsTrusted(string $command): bool
{
    $token = pmssAppVersionProbeLeadingToken($command);
    if ($token === null) {
        return false;
    }

    $candidate = $token[0] === '/' ? $token : pmssCommandPath($token);
    if ($candidate === '' || !pmssCommandPathCandidateIsSafe($candidate)) {
        return false;
    }

    $resolved = @realpath($candidate);
    $linkStat = @lstat($candidate);
    $fileStat = is_string($resolved) ? @stat($resolved) : false;
    if (!is_string($resolved)
        || !is_array($linkStat)
        || !is_array($fileStat)
        || !is_file($resolved)
        || !is_executable($resolved)
        || !pmssAppVersionProbeSystemPathIsSafe($resolved)
        || !pmssAppVersionProbeSystemUidIsTrusted($linkStat['uid'] ?? null)
        || !pmssAppVersionProbeSystemUidIsTrusted($fileStat['uid'] ?? null)
        || (((int) ($fileStat['mode'] ?? 0) & 0022) !== 0)
        || !pmssAppVersionProbeParentChainIsTrusted($resolved)
    ) {
        return false;
    }

    return true;
}

/** Capture a probe only after its executable path passes the trust boundary. */
function pmssAppVersionProbeCapture(string $command, int $timeoutSeconds): array
{
    if (!pmssAppVersionProbeBinaryIsTrusted($command)) {
        logmsg('[WARN] Refusing app version probe for an untrusted system binary');
        return ['rc' => 126, 'stdout' => '', 'stderr' => ''];
    }

    return pmssCommandCapture($command, max(1, $timeoutSeconds));
}

/** Run a bounded app version probe without letting daemon-capable binaries wedge updates. */
function pmssAppVersionProbeOutput(string $command, int $timeoutSeconds = PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS): string
{
    $result = pmssAppVersionProbeCapture($command, $timeoutSeconds);
    if ((int) ($result['rc'] ?? 1) !== 0) {
        return is_string($result['stderr'] ?? null) ? $result['stderr'] : '';
    }

    return is_string($result['stdout'] ?? null) ? $result['stdout'] : '';
}

/** Return whether a trusted app probe completed successfully. */
function pmssAppVersionProbeSucceeded(string $command, int $timeoutSeconds = PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS): bool
{
    return (int) (pmssAppVersionProbeCapture($command, $timeoutSeconds)['rc'] ?? 1) === 0;
}

/** Return the first regex capture from bounded app version probes. */
function pmssAppVersionProbeMatch(array $commands, string $pattern, int $capture = 0, int $timeoutSeconds = PMSS_APP_VERSION_PROBE_TIMEOUT_SECONDS): ?string
{
    foreach ($commands as $command) {
        if (preg_match($pattern, pmssAppVersionProbeOutput((string) $command, $timeoutSeconds), $match) === 1) return (string) ($match[$capture] ?? $match[0]);
    }
    return null;
}
