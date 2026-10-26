<?php
/** File-backed benchmark reservation, inode-checked cleanup and jobs. @license GPL-3.0-only */

/** Reserve the benchmark name before fio or fallocate can touch it. */
function storageBenchmarkFileReserve(string $path): ?array
{
    $handle = @fopen($path, 'x');
    if ($handle === false) return null;
    $identity = @fstat($handle);
    @fclose($handle);
    return is_array($identity) ? ['dev' => $identity['dev'], 'ino' => $identity['ino']] : null;
}

/** Never remove a path replaced after this run reserved its file. */
function storageBenchmarkFileCleanup(string $path, array $identity): bool
{
    clearstatcache(true, $path);
    $current = @lstat($path);
    return is_array($current) && pmssFilesystemStatSameInode($current, $identity) && @unlink($path);
}

function storageBenchmarkRunFileTests(string $targetDir, string $jsonLog, string $runTs, string $label, string $runId, string $mntDev, string $fs, int $requested, int $runtime): array
{
    $free = (int) storageBenchmarkRequireCommandField('df -PB1 '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $4}'), 'free space', true);
    $use = (int) min($requested, floor($free * 0.8));
    if ($use <= 0) storageBenchmarkFail("Insufficient free space.\n");
    $testFile = rtrim($targetDir, '/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat';
    $testFileIdentity = storageBenchmarkFileReserve($testFile);
    if ($testFileIdentity === null) storageBenchmarkFail("Error: failed to reserve benchmark file.\n");
    register_shutdown_function(static function () use ($testFile, $testFileIdentity): void {
        storageBenchmarkFileCleanup($testFile, $testFileIdentity);
    });
    if (pmssCommandPath('fallocate') !== '' && runCommand('fallocate -l '.$use.' '.escapeshellarg($testFile)) !== 0) storageBenchmarkFail("Error: failed to preallocate benchmark file.\n");
    $summary = [];
    $base = storageBenchmarkEntryBase($runTs, $label, $runId) + ['target_dir' => $targetDir, 'device' => $mntDev, 'filesystem' => $fs];
    $params = array_fill_keys(['rw', 'rwmixread', 'bs', 'bssplit', 'iodepth', 'numjobs', 'direct', 'runtime'], null) + ['size_bytes' => $use];
    foreach (storageBenchmarkFioJobsRun($jsonLog, $testFile, $use, $runtime, storageBenchmarkFileJobs($runtime), $base, $params) as $name => $res) {
        if ($res['ok']) $summary[] = [$name, $res['result']['read_bw_MBps'], $res['result']['write_bw_MBps'], $res['result']['read_iops'], $res['result']['write_iops'], $res['result']['read_p95_ms'], $res['result']['write_p95_ms']];
    }
    storageBenchmarkFileCleanup($testFile, $testFileIdentity);
    return $summary;
}
