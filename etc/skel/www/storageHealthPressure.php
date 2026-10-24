<?php
/** Live PSI and fresh public ioping state; loaded by storageHealthNotice.php. */

const PMSS_STORAGE_PSI_HEAVY = 20.0;
const PMSS_STORAGE_IOPING_SLOW = 100.0;
const PMSS_STORAGE_HEAVY_TITLE = 'Shared storage is under heavy load';
const PMSS_STORAGE_HEAVY_MESSAGE = 'Disk-backed services may respond more slowly while the server-wide I/O queue clears. Repeated service restarts will not make this condition clear faster.';

if (!function_exists('pmssStorageHealthHostPressureStateRead')) {
    /**
     * Read live full I/O PSI and an optional fresh ioping measurement.
     */
    function pmssStorageHealthHostPressureStateRead(
        string $path = '/var/lib/pmss/public/host-pressure.json',
        ?int $now = null,
        string $psiPath = '/proc/pressure/io'
    ): array {
        if (!file_exists($psiPath)) return ['status' => 'absent'];
        $psiRaw = pmssCustomerFileRead($psiPath, true);
        if (!is_string($psiRaw) || !preg_match('/^full\s+[^\r\n]*\bavg300=([0-9]+(?:\.[0-9]+)?)(?:\s|$)/m', $psiRaw, $match)) {
            return ['status' => 'unavailable'];
        }
        $psi = pmssCustomerNonnegativeFloat($match[1]);
        if ($psi === null) return ['status' => 'unavailable'];

        $now = $now ?? time();
        $state = ['status' => 'normal', 'psi_io_full_avg300' => $psi];
        $raw = pmssCustomerFileRead($path);
        if (is_string($raw) && $raw !== '' && strlen($raw) <= 4096) {
            $payload = pmssJsonDecodeAssoc($raw);
            $timestamp = is_array($payload) ? pmssCustomerUnsignedIntegerValue($payload['timestamp'] ?? null) : null;
            if ($timestamp !== null && $now > 0 && $timestamp <= $now && $now - $timestamp <= 900) {
                $latency = pmssCustomerNonnegativeFloat($payload['ioping_home_ms'] ?? null);
                if ($latency !== null) {
                    $state['ioping_home_ms'] = $latency;
                    $state['ioping_age_min'] = (int) floor(($now - $timestamp) / 60);
                }
            }
        }
        if ($psi >= PMSS_STORAGE_PSI_HEAVY || (isset($state['ioping_home_ms']) && $state['ioping_home_ms'] > PMSS_STORAGE_IOPING_SLOW)) {
            $state['status'] = 'heavy';
        }
        return $state;
    }
}

