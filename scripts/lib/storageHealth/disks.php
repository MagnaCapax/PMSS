<?php
/**
 * Disk inventory helpers (lsblk parsing).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Parse `lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE`.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthListDisksFromLsblk(string $out): array
{
    $devs = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || ($partCount = count($parts)) < 3) {
            continue;
        }
        $kname = (string) $parts[0];
        $type = (string) $parts[1];
        $rota = (int) $parts[2];
        if ($type !== 'disk') {
            continue;
        }
        if (strpos($kname, 'loop') === 0 || strpos($kname, 'ram') === 0) {
            continue;
        }
        $sizeStr = (string) ($parts[$partCount - 1] ?? '');
        $serial = (string) ($parts[$partCount - 2] ?? '');
        $model = implode(' ', array_slice($parts, 3, max(0, $partCount - 5)));
        $devs[] = [
            'path' => '/dev/'.$kname,
            'kname' => $kname,
            'rota' => $rota,
            'model' => $model,
            'serial' => $serial,
            'size' => $sizeStr,
        ];
    }
    return $devs;
}

/**
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthListDisks(): array
{
    $out = shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null');
    if (!$out) {
        return [];
    }
    return pmssStorageHealthListDisksFromLsblk($out);
}
