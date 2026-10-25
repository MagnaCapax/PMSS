<?php
/**
 * Read-only rollout census from existing slice-local performance telemetry.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/policy.php';

/** Read bounded live and recent rotated JSONL logs without creating any state. */
function pmssCgroupIopsCensusSamples(string $path): iterable
{
    foreach ([$path.'.1', $path] as $file) {
        $raw = pmssReadRegularFileContentsVerified($file, null, 16 * 1024 * 1024);
        if ($raw === null) continue;
        $lines = explode("\n", $raw);
        array_pop($lines); // A final partial JSON record is not a completed observation.
        foreach ($lines as $line) {
            if (($sample = pmssJsonDecodeAssoc($line)) !== null) yield $sample;
        }
    }
}

/** Compute a nearest-rank P95 of valid counter intervals from the last 24 hours. */
function pmssCgroupIopsCensusReadP95(iterable $samples, int $uid, int $now): array
{
    $rows = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || ($sample['uid'] ?? null) !== $uid || !is_string($sample['ts'] ?? null)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $sample['ts']) !== 1
            || !is_int($sample['io_ops_read'] ?? null) || $sample['io_ops_read'] < 0) continue;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $sample['ts']);
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $sample['ts']) continue;
        $time = $date->getTimestamp();
        if ($time >= $now - 86400 && $time <= $now) $rows[$time] = $sample['io_ops_read'];
    }
    ksort($rows, SORT_NUMERIC);
    $rates = []; $previousTime = null; $previousOps = null;
    foreach ($rows as $time => $ops) {
        // Resets and gaps over three collector periods cannot provide a valid rate.
        if ($previousTime !== null && $time > $previousTime && $time - $previousTime <= 900 && $ops >= $previousOps) {
            $rates[] = ($ops - $previousOps) / ($time - $previousTime);
        }
        $previousTime = $time; $previousOps = $ops;
    }
    sort($rates, SORT_NUMERIC);
    return ['p95' => $rates === [] ? null : $rates[(int) ceil(0.95 * count($rates)) - 1], 'intervals' => count($rates)];
}

/** Print one TSV row; child directories are a warning, not proof of process placement. */
function pmssCgroupIopsCensusPrint(string $user, int $uid, ?int $readIops, ?int $writeIops, string $sliceDir): void
{
    $stats = pmssCgroupIopsCensusReadP95(pmssCgroupIopsCensusSamples('/var/log/pmss/metrics/'.$user), $uid, time());
    $children = is_dir($sliceDir) ? glob($sliceDir.'/*', GLOB_ONLYDIR) : false;
    printf("%s\t%d\t%s\t%s\t%s\t%d\t%s\n", $user, $uid, $readIops ?? 'none', $writeIops ?? 'none',
        $stats['p95'] === null ? 'n/a' : number_format($stats['p95'], 2, '.', ''), $stats['intervals'],
        $children === false ? 'n/a' : count($children));
}
