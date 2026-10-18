<?php
/**
 * Activate ProFTPD's TLS module in its Debian-owned module list.
 *
 * Preserve every other directive and the existing newline convention.
 * @license GPL-3.0-only
 */

function pmssProftpdTlsModuleContent(string $contents): string
{
    if (preg_match('/^\h*LoadModule\h+mod_tls\.c(?=\h|$)/im', $contents)) {
        return $contents;
    }

    $updated = preg_replace('/^(\h*)#\h*(LoadModule\h+mod_tls\.c)(?=\h|$)/im', '$1$2', $contents, 1, $count);
    if ($count > 0) {
        return $updated;
    }

    $newline = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
    return $contents.(($contents !== '' && !preg_match('/\R$/', $contents)) ? $newline : '').'LoadModule mod_tls.c'.$newline;
}
