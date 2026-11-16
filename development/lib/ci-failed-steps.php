<?php
/**
 * Write only the failing steps of GitHub Actions job logs, so a CI fix pass gets the error text even when a whole job
 * log is too large to pass along. A step starts at its "##[group]Run " line; it failed when an "##[error]" line follows
 * before the next step starts. Each failed step keeps its first 2 KiB and as much of its end as fits the cap.
 * Usage: php ci-failed-steps.php OUT JOBLOG [JOBLOG ...]   (writes nothing and exits 0 when no step failed)
 * Called by development/agentic-ci.sh; reads local files only.
 */

const CI_FAILED_STEPS_CAP = 524288;   // bytes per output file
const CI_FAILED_STEPS_HEAD = 2048;    // the step's command and environment lines

/** Failed step sections of one job log, in order, as strings. */
function ciFailedSteps(string $log): array
{
    $lines = preg_split('/\R/', $log);
    $sections = [];
    $start = null;
    foreach ($lines as $i => $line) {
        if (strpos($line, '##[group]Run ') !== false) {
            $start = $i;
        } elseif (strpos($line, '##[error]') !== false) {
            $from = $start ?? 0;
            $sections[$from] = $i;   // a later error in the same step extends that step's section
        }
    }
    $out = [];
    foreach ($sections as $from => $to) {
        $out[] = implode("\n", array_slice($lines, $from, $to - $from + 1)) . "\n";
    }
    return $out;
}

/** Keep the head and the tail of a section that is larger than its share of the cap. */
function ciFailedStepsBound(string $text, int $cap): string
{
    if (strlen($text) <= $cap) {
        return $text;
    }
    $tail = $cap - CI_FAILED_STEPS_HEAD;
    $cut = strlen($text) - CI_FAILED_STEPS_HEAD - $tail;
    return substr($text, 0, CI_FAILED_STEPS_HEAD) . "[... $cut bytes cut ...]\n" . substr($text, -$tail);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    if ($argc < 3) {
        fwrite(STDERR, "Usage: ci-failed-steps.php OUT JOBLOG [JOBLOG ...]\n");
        exit(2);
    }
    $parts = [];
    foreach (array_slice($argv, 2) as $path) {
        $log = is_file($path) ? (string) file_get_contents($path) : '';
        foreach (ciFailedSteps($log) as $section) {
            $parts[] = "===== failed step in " . basename($path) . " =====\n" . $section;
        }
    }
    if ($parts === []) {
        exit(0);
    }
    $share = intdiv(CI_FAILED_STEPS_CAP, count($parts));
    $body = implode('', array_map(static function (string $p) use ($share): string {
        return ciFailedStepsBound($p, $share);
    }, $parts));
    if (file_put_contents($argv[1], $body) === false) {
        fwrite(STDERR, "[codex-ci] cannot write failed-steps excerpt\n");
        exit(1);
    }
    exit(0);
}
