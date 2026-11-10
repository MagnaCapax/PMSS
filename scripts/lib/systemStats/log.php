<?php
/** Legacy system-stats line rendering and checked append publication. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/** Render one parseable cron log line from collected stats. */
function pmssSystemStatsLogLine(array $stats, ?string $timestamp = null): string
{
    $line = $timestamp ?? date('Ymd H:i:s');
    foreach ([
        'load' => 'load', 'cpu_iowait' => 'cpuIowait', 'mem_total' => 'memTotal', 'mem_free' => 'memFree',
        'mem_cache' => 'memCache', 'mem_buffers' => 'memBuffers', 'swap_total' => 'swapTotal', 'swap_free' => 'swapFree', 'disk_busy' => 'diskBusy', 'ioping_root' => 'iopingRoot', 'ioping_home' => 'iopingHome', 'top_mem' => 'topMem', 'psi_io' => 'psiIo', 'psi_mem' => 'psiMem', 'jbd2_home' => 'jbd2Home',
    ] as $label => $key) {
        $line .= ' | '.$label.':'.(string) ($stats[$key] ?? 'na');
    }
    return $line;
}

/** Append one system-stats record only through a plain file and safe ancestors. */
function pmssSystemStatsAppendLogLine(string $path, string $line): bool
{
    if ($path === '' || pmssFilesystemPathHasNulByte($path) || $path[0] !== '/') return false;
    // Validate before mkdir too, so redirected ancestors cannot create directories elsewhere.
    if (!pmssPathTargetIsSafe($path, false)) return false;
    if (!pmssDirEnsureExists(dirname($path), 0755)) return false;
    if (!pmssPathTargetIsSafe($path, false)) return false;

    $record = rtrim($line, "\r\n")."\n";
    // A partial record must reach the cron caller's existing failure warning.
    return pmssFileWriteComplete($path, $record, FILE_APPEND | LOCK_EX);
}
