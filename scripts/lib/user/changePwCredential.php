<?php
/**
 * Shadow credential update for changePw, with a Debian 10 fallback.
 *
 * @license GPL-3.0-only
 */

/** Read exactly one credential line; null means no line was received. */
function pmssChangePwPasswordReadLine($stream): ?string
{
    $line = is_resource($stream) ? fgets($stream) : false;
    if (!is_string($line)) {
        return null;
    }
    return preg_replace('/\r?\n$/', '', $line);
}

/** Use bcrypt only where pam_unix accepts PHP's $2y$ hashes. */
function pmssChangePwBcryptSupported(string $versionPath = '/etc/debian_version'): bool
{
    $version = @file_get_contents($versionPath);
    if (!is_string($version) || !preg_match('/^([0-9]+)(?:\.[0-9]+)?$/D', trim($version), $matches)) {
        return false;
    }

    return (int) $matches[1] >= 11;
}

/**
 * Set shadow through stdin, returning its hash only after a successful update.
 * The caller writes the returned hash to htpasswd on supported Debian releases.
 *
 * @return array{0: int, 1: string}
 */
function pmssChangePwShadowUpdate(string $username, string $password, bool $useBcrypt, array &$output): array
{
    $output = [];
    try {
        $hash = $useBcrypt ? password_hash($password, PASSWORD_BCRYPT) : '';
    } catch (Throwable $exception) {
        return [1, ''];
    }
    if ($useBcrypt && (!is_string($hash) || strpos($hash, '$2y$') !== 0)) {
        return [1, ''];
    }

    // Neither the plaintext nor the hash may appear in process arguments.
    $command = $useBcrypt ? 'chpasswd -e' : 'passwd '.escapeshellarg($username);
    $payload = $useBcrypt ? $username.':'.$hash."\n" : $password."\n".$password."\n";
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w']];
    $process = @proc_open($command.' 2>&1', $descriptors, $pipes);
    if (!is_resource($process)) {
        return [1, ''];
    }

    $written = fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if (is_string($result) && trim($result) !== '') {
        $output = explode("\n", trim($result));
    }

    return [$written === strlen($payload) ? $status : 1, $status === 0 && $written === strlen($payload) ? $hash : ''];
}
