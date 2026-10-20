<?php
/** Shared account-only primitives for agent installers; never load operator /scripts. */

/** The account CLI carries its own command lookup across the /scripts boundary. */
function pmssAgentCommandPath(string $binary): string
{
    if (function_exists('pmssCommandPath')) return pmssCommandPath($binary);
    if (preg_match('/^[A-Za-z0-9._+-]+$/D', $binary) !== 1) return '';
    $raw = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
    if (!is_string($raw) || strpos($raw, "\0") !== false) return '';
    $path = trim($raw);
    return strpos($path, '/') === 0 && strpbrk($path, "\0\r\n") === false
        && is_executable($path) ? $path : '';
}

/** Require an ordinary caller and its own non-linked home. */
function pmssAgentHome(): string
{
    $home = getenv('HOME');
    if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || !is_string($home)
        || $home === '' || !is_dir($home) || is_link($home) || fileowner($home) !== posix_geteuid()) {
        throw new RuntimeException('Run as an account owner with an ordinary HOME');
    }
    return rtrim($home, '/');
}

/** Copy OpenClaw's credential gate: no links, foreign ownership or loose mode. */
function pmssAgentVirsilipasCredentials(string $home): ?array
{
    $file = $home.'/.virsilipasKey';
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file) || fileowner($file) !== posix_geteuid()
        || (fileperms($file) & 0077) !== 0 || filesize($file) > 4096) return null;
    $settings = @parse_ini_string((string) file_get_contents($file), false, INI_SCANNER_RAW);
    $key = is_array($settings) ? ($settings['key'] ?? '') : '';
    $endpoint = is_array($settings) ? ($settings['endpoint'] ?? '') : '';
    if (!is_string($key) || $key === '' || preg_match('/[\s\p{Z}\p{C}]/u', $key) !== 0
        || !is_string($endpoint)
        || !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $endpoint)) return null;
    return array('key' => $key, 'endpoint' => $endpoint);
}

/** Only a platform-assigned first-line port may become a listener. */
function pmssAgentReservedPort(string $path): ?int
{
    if (!is_file($path) || is_link($path) || filesize($path) > 32) return null;
    $line = file_get_contents($path);
    if (!is_string($line) || !preg_match('/^([0-9]{1,5})\r?\n?$/D', $line, $m)) return null;
    $port = (int) $m[1];
    return $port >= 1 && $port <= 65535 ? $port : null;
}

/** Preserve foreign crontab bytes and own exactly the tagged lines. */
function pmssAgentCronMerge(string $current, string $tag, array $managed): string
{
    $kept = '';
    foreach (preg_split('/(?<=\n)/', $current, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        if (preg_match('/ '.preg_quote($tag, '/').'\r?\n?$/D', $line) !== 1) $kept .= $line;
    }
    if (!$managed) return $kept;
    if ($kept !== '' && substr($kept, -1) !== "\n") $kept .= "\n";
    foreach ($managed as $line) $kept .= $line.' '.$tag."\n";
    return $kept;
}

/** Run a fixed argument vector; callers never place secrets in it. */
function pmssAgentRun(array $args, ?array $env = null): array
{
    $command = implode(' ', array_map('escapeshellarg', $args));
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
        2 => array('redirect', 1)), $pipes, null, $env);
    if (!is_resource($process)) return array(1, 'Could not launch command');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return array(proc_close($process), (string) $output);
}

/** Install a merged crontab from a private file, never stdin. */
function pmssAgentCronApply(string $home, string $tag, array $managed): void
{
    list($rc, $current) = pmssAgentRun(array('crontab', '-l'));
    if ($rc !== 0 && strpos($current, 'no crontab for') === false) throw new RuntimeException('Could not read crontab');
    if ($rc !== 0) $current = '';
    $merged = pmssAgentCronMerge($current, $tag, $managed);
    if ($merged === $current) return;
    $temp = tempnam($home, '.pmss-agent-cron-');
    if ($temp === false) throw new RuntimeException('Could not stage crontab');
    try {
        chmod($temp, 0600);
        if (file_put_contents($temp, $merged) !== strlen($merged)) throw new RuntimeException('Could not write crontab');
        list($rc) = pmssAgentRun(array('crontab', $temp));
        if ($rc !== 0) throw new RuntimeException('Could not install crontab');
    } finally { unlink($temp); }
}

/** Return the file quota row used by the older account installer. */
function pmssAgentQuotaHeadroom(string $output): ?array
{
    foreach (preg_split('/\r?\n/', $output) as $line) {
        if (!preg_match('/^\s*\/\S+\s+\d+\*?\s+\d+\s+\d+\s+(?:\S+\s+)?(\d+)\*?\s+(\d+)\s+(\d+)(?:\s+\S+)?\s*$/D', $line, $m)) continue;
        if ((int) $m[2] > 0) return array('used' => (int) $m[1], 'soft' => (int) $m[2]);
    }
    return null;
}
