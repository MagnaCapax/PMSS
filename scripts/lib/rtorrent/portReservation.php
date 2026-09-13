<?php
/**
 * Exclusive legacy rTorrent port allocation within a caller-selected namespace.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime/filesystem.php';

/** Reserve one port or throw; the caller owns the transaction lock and rollback. */
function pmssRtorrentPortReserve(string $directoryBase, $type, $rangeStart = 2000, $rangeEnd = 65000): int
{
    // Validate the namespace and range before creating either directory.
    $type = (string) $type;
    if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $type) !== 1) {
        throw new InvalidArgumentException('Invalid rTorrent port reservation type');
    }
    $rangeStart = filter_var($rangeStart, FILTER_VALIDATE_INT);
    $rangeEnd = filter_var($rangeEnd, FILTER_VALIDATE_INT);
    if ($rangeStart === false || $rangeEnd === false || $rangeStart < 1 || $rangeEnd > 65535 || $rangeStart > $rangeEnd) {
        throw new InvalidArgumentException('Invalid rTorrent port reservation range');
    }

    $directoryType = $directoryBase.'/'.$type;
    foreach ([
        [$directoryBase, 'Unable to create port reservation base directory: '],
        [$directoryType, 'Unable to create port reservation directory: '],
    ] as [$directory, $errorPrefix]) {
        if (!pmssDirEnsureExists($directory, 0755)) {
            throw new RuntimeException($errorPrefix.$directory);
        }
    }

    $rangeSize = $rangeEnd - $rangeStart + 1;
    $attempts = min(max($rangeSize * 2, 16), 4096);
    // Keep the random budget, then visit every slot in order through the same writer.
    for ($attempt = 0; $attempt < $attempts + $rangeSize; $attempt++) {
        $port = $attempt < $attempts ? rand($rangeStart, $rangeEnd) : $rangeStart + $attempt - $attempts;
        $path = $directoryType.'/'.$port;
        if (is_link($path)) {
            continue;
        }
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (file_exists($path) || is_link($path)) {
                continue;
            }
            throw new RuntimeException('Unable to reserve port file: '.$path);
        }

        @fclose($handle);
        if (!is_file($path) || is_link($path)) {
            @unlink($path);
            throw new RuntimeException('Unable to reserve port file: '.$path);
        }
        return $port;
    }

    throw new RuntimeException('No available rTorrent '.$type.' port reservation slots');
}
