<?php
/**
 * Scratch file helpers for user transfers.
 *
 * @license GPL-3.0-only
 */

/**
 * Create a private scratch directory under /root for temporary scripts.
 */
function pmssUserTransferScratchDir(): string
{
    $token = '';
    try {
        $token = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $token = sha1(microtime(true).'-'.mt_rand());
    }
    $dir = '/root/pmss-userTransfer-'.$token;
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create scratch directory: '.$dir, 1);
    }
    @chmod($dir, 0700);
    return $dir;
}

/**
 * Write a file with the given contents and permissions.
 */
function pmssUserTransferWriteFile(string $path, string $contents, int $mode): void
{
    if (@file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed writing: '.$path, 1);
    }
    @chmod($path, $mode);
}

