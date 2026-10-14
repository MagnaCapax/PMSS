<?php
/**
 * Shared sysfs walk from a block device to the disks carrying its I/O.
 *
 * A failed branch leaves a partial set for callers that can use it.
 * @license GPL-3.0-only
 */

/** @return array{leaves:array<int, string>,complete:bool} */
function pmssBlockDeviceLeaves(string $device, string $root, ?callable $globber = null): array
{
    $leaves = []; $complete = true;
    $globber = $globber ?: 'glob';
    $walk = static function (string $name, int $depth) use (&$walk, &$leaves, &$complete, $root, $globber): void {
        // Bound cycles and reject names before constructing a sysfs path.
        if ($depth > 8 || preg_match('/\A[A-Za-z0-9._+-]+\z/', $name) !== 1) { $complete = false; return; }
        $path = $root.'/'.$name;
        if (!is_dir($path)) {
            $partitions = $globber($root.'/*/'.$name.'/partition');
            if (!is_array($partitions) || count($partitions) !== 1) { $complete = false; return; }
            $walk(basename(dirname(dirname($partitions[0]))), $depth + 1);
            return;
        }
        $slaves = $globber($path.'/slaves/*');
        if (!is_array($slaves)) { $complete = false; return; }
        if ($slaves !== []) {
            foreach ($slaves as $slave) $walk(basename($slave), $depth + 1);
            return;
        }
        // Class/block partitions are symlinks; sys/block fixtures may be paths.
        if (is_file($path.'/partition')) {
            $real = realpath($path);
            if ($real === false) { $complete = false; return; }
            $name = basename(dirname($real));
            if (preg_match('/\A[A-Za-z0-9._+-]+\z/', $name) !== 1 || !is_dir($root.'/'.$name)) { $complete = false; return; }
        }
        $leaves[$name] = true;
    };
    $walk($device, 0);
    return ['leaves' => array_keys($leaves), 'complete' => $complete];
}
