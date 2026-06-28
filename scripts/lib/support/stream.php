<?php
/**
 * Support command stream I/O helpers.
 *
 * Shared by diagnostics snapshot writing and mail transports so either side
 * can use reliable full-buffer writes without loading the other subsystem.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Write an entire payload to a writable stream or fail loudly.
 *
 * @param resource $stream
 */
function pmssSupportStreamWriteAll($stream, string $payload, string $context): void
{
    $offset = 0;
    $length = strlen($payload);

    if ($length > 0 && !is_resource($stream)) {
        throw new RuntimeException('Unable to write '.$context.'.');
    }

    while ($offset < $length) {
        $written = @fwrite($stream, substr($payload, $offset));
        if (!is_int($written) || $written < 1) {
            $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
            $suffix = !empty($meta['timed_out']) ? ' timed out' : '';
            throw new RuntimeException('Unable to write '.$context.$suffix.'.');
        }
        $offset += $written;
    }
}
