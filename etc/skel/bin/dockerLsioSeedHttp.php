<?php
/** Account-local Docker I/O for first-run login setup (PHP 7.3). */

/** Capture output without exposing request bodies, API keys or replies in logs. */
function pmssDockerLsioSeedCapture(array $command, string $input = ''): ?string
{
    $process = proc_open(pmssDockerInstallLsioShellCommand($command), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    $written = $input === '' || fwrite($pipes[0], $input) === strlen($input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rc = proc_close($process);
    return $written && $rc === 0 && is_string($output) ? $output : null;
}

/** Curl config quoting keeps generated secrets off both host and container argv. */
function pmssDockerLsioSeedCurlValue(string $value): string
{
    return '"'.str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], $value).'"';
}

/** Run HTTP only inside the new container, never through a host-published port. */
function pmssDockerLsioSeedHttp(string $app, string $url, string $method = 'GET', ?array $payload = null, array $headers = []): array
{
    $config = 'url = '.pmssDockerLsioSeedCurlValue($url)."\nrequest = ".pmssDockerLsioSeedCurlValue($method)."\n";
    foreach ($headers as $header) {
        $config .= 'header = '.pmssDockerLsioSeedCurlValue($header)."\n";
    }
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['code' => 0, 'body' => ''];
        }
        $config .= "header = \"Content-Type: application/json\"\n";
        $config .= 'data = '.pmssDockerLsioSeedCurlValue($json)."\n";
    }
    $reply = pmssDockerLsioSeedCapture([
        'docker', 'exec', '-i', $app, 'curl', '--silent', '--show-error', '--noproxy', '*',
        '--connect-timeout', '2', '--max-time', '10', '--write-out', "\n%{http_code}", '--config', '-',
    ], $config);
    if ($reply === null || !preg_match('/\n([0-9]{3})$/', $reply, $match)) {
        return ['code' => 0, 'body' => ''];
    }
    return ['code' => (int) $match[1], 'body' => substr($reply, 0, -4)];
}

/** Bounded first-start wait; unknown replies always remain unpublished. */
function pmssDockerLsioSeedWait(callable $probe, int $attempts = 60): bool
{
    for ($attempt = 0; $attempt < $attempts; ++$attempt) {
        if ($probe()) {
            return true;
        }
        if ($attempt + 1 < $attempts) {
            sleep(1);
        }
    }
    return false;
}
