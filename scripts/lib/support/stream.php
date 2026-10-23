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

require_once __DIR__.'/../runtime/filesystem.php';

/**
 * Write an entire payload to a writable stream or fail loudly.
 *
 * @param resource $stream
 */
function pmssSupportStreamWriteAll($stream, string $payload, string $context): void
{
    if ($payload !== '' && !is_resource($stream)) {
        throw new RuntimeException('Unable to write '.$context.'.');
    }

    if (!pmssStreamWriteAll($stream, $payload)) {
        $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
        $suffix = !empty($meta['timed_out']) ? ' timed out' : '';
        throw new RuntimeException('Unable to write '.$context.$suffix.'.');
    }
}
