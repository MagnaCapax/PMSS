<?php
/** Account validation and bounded account-shell execution. Loaded by cli.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Resolve only managed account names with a positive UID before shelling out. */
function userDockerAccountInfo(string $user, ?string &$reason = null): ?array
{
    $reason = null;
    if (!pmssValidateUsername($user)) {
        $reason = 'invalid';
        return null;
    }

    $info = pmssUserAccountLookup($user);
    if ($info === null) {
        $reason = 'unknown';
        return null;
    }

    if (pmssPasswdEntryPositiveUid($info) === null) {
        $reason = 'unsafe_uid';
        return null;
    }

    return $info;
}

// Helper to execute a command as the target user via su, with an optional timeout.
function userDockerRunAs(
    string $user,
    string $cmd,
    ?int $timeoutSeconds = null,
    ?int &$rc = null,
    bool $placeInUserSlice = false
): string
{
    static $timeoutBinResolved = false;
    static $timeoutBin = null;

    $target = userDockerAccountInfo($user);
    if ($target === null) {
        $rc = 127;
        return '';
    }

    $currentUid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
    if ($target !== null && $currentUid > 0 && $currentUid === (int) $target['uid']) {
        // When already running as the target user (e.g. per-user web UI),
        // avoid `su` so interactive password prompts do not block automation.
        $wrapper = sprintf('/bin/bash -lc %s', escapeshellarg($cmd));
    } elseif ($placeInUserSlice && $currentUid === 0) {
        $wrapper = pmssBuildUserServiceShellCommand($user, $cmd);
    } else {
        $wrapper = pmssBuildUserShellCommand($user, $cmd);
    }

    if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
        if (!$timeoutBinResolved) {
            $timeoutBinResolved = true;
            $candidates = ['/usr/bin/timeout', '/bin/timeout'];
            foreach ($candidates as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $timeoutBin = $candidate;
                    break;
                }
            }
        }
        if ($timeoutBin !== null) {
            $wrapper = sprintf(
                '%s --kill-after=%s %s %s',
                escapeshellarg($timeoutBin),
                escapeshellarg('5s'),
                escapeshellarg($timeoutSeconds.'s'),
                $wrapper
            );
        }
    }
    $out = [];
    $rcLocal = 0;
    exec($wrapper, $out, $rcLocal);
    $rc = $rcLocal;
    return implode("\n", $out);
}
