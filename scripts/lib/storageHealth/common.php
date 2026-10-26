<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/deviceMetrics.php';

/**
 * Parse the common `lsblk -dn` disk inventory format used by storage tools.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthDiskInventoryFromLsblk(string $lsblkOut): array
{
    $disks = [];
    foreach (pmssConfigTextRows($lsblkOut, 3, []) as $parts) {
        $kname = (string) $parts[0];
        // KNAME comes from lsblk; reject shell/path syntax before composing /dev/<name>.
        if ($kname === '' || $kname === '.' || $kname === '..' || preg_match('/^[A-Za-z0-9._!+-]+$/D', $kname) !== 1) continue;
        if ($parts[1] !== 'disk' || strpos($kname, 'loop') === 0 || strpos($kname, 'ram') === 0) continue;
        $disks[] = ['path' => '/dev/'.$kname, 'kname' => $kname, 'rota' => (int) $parts[2], 'model' => implode(' ', array_slice($parts, 3, max(0, count($parts) - 5))), 'serial' => (string) ($parts[count($parts) - 2] ?? ''), 'size' => (string) ($parts[count($parts) - 1] ?? '')];
    }
    return $disks;
}

/**
 * Read disk inventory only after lsblk reports a clean exit.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthDiskInventoryRead(): array
{
    $result = pmssCommandCapture('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE', 30);
    if ((int) ($result['rc'] ?? 1) !== 0) {
        return [];
    }

    return pmssStorageHealthDiskInventoryFromLsblk((string) ($result['stdout'] ?? ''));
}

/**
 * Read the latest entry per (kind, device/array) key from a JSONL file.
 *
 * @return array<string, array<string, mixed>>
 */
function pmssStorageHealthReadLastEntries(string $path): array
{
    $last = [];
    pmssJsonLineFileEach($path, static function (array $entry) use (&$last): void {
        $last[(string) ($entry['kind'] ?? '').'::'.(string) ($entry['device'] ?? ($entry['array'] ?? 'global'))] = $entry;
    });
    return $last;
}

/**
 * Bind a probe lock to the external storage command, not to the PHP wrapper.
 *
 * A probe can outlive the timeout wrapper while blocked in device I/O. Keeping
 * the lock on the exec'd probe prevents the next snapshot from launching a
 * competing probe for the same device while allowing other devices through.
 *
 * @return array{command:string,lock_exit_code:int}
 */
function pmssStorageHealthProbeCommand(string $kind, string $kname, string $command): array
{
    $flock = pmssCommandPath('flock');
    if ($flock === ''
        || preg_match('/^[A-Za-z0-9._!+-]+$/D', $kind) !== 1
        || preg_match('/^[A-Za-z0-9._!+-]+$/D', $kname) !== 1) {
        return ['command' => $command, 'lock_exit_code' => 0];
    }

    return [
        'command' => escapeshellarg($flock).' -xn -E 75 '.escapeshellarg(pmssRuntimeLockPath('pmss-storage-'.$kind.'-'.$kname.'.lock')).' '.$command,
        'lock_exit_code' => 75,
    ];
}
