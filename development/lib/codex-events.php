<?php
/**
 * Append one development-runner lifecycle event as locked JSONL.
 * Usage: php codex-events.php PATH EVENT LEVEL STEP RUN_ID RC DURATION_MS DETAIL DISTRO
 * Called by development/lib/codex-common.sh; no network or server operations.
 */

if (($argv[1] ?? '') === '--help') {
    echo "Usage: codex-events.php PATH EVENT LEVEL STEP RUN_ID RC DURATION_MS DETAIL DISTRO\n";
    exit(0);
}
if ($argc !== 10) {
    fwrite(STDERR, "[codex-run] event writer requires nine arguments\n");
    exit(2);
}

// Reject malformed numeric fields rather than generating a plausible success record.
foreach ([6, 7] as $index) {
    if ($argv[$index] !== '' && !ctype_digit($argv[$index])) {
        fwrite(STDERR, "[codex-run] invalid numeric event field\n");
        exit(2);
    }
}
$event = [
    'timestamp' => gmdate('Y-m-d H:i:s'),
    'timezone' => 'UTC',
    'event' => $argv[2],
    'level' => $argv[3],
    'step' => $argv[4],
    'rc' => $argv[6] === '' ? null : (int) $argv[6],
    'duration_ms' => $argv[7] === '' ? null : (int) $argv[7],
    'host' => explode('.', gethostname() ?: 'unknown')[0],
    'distro' => $argv[9],
    'correlationId' => $argv[5],
    'detail' => $argv[8],
];
$line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$directory = dirname($argv[1]);
if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
    || $line === false
    || @file_put_contents($argv[1], $line."\n", FILE_APPEND | LOCK_EX) !== strlen($line."\n")) {
    fwrite(STDERR, "[codex-run] cannot append JSONL event to ".$argv[1]."\n");
    exit(1);
}
