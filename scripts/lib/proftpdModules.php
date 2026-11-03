<?php
/**
 * Activate ProFTPD's TLS module in its Debian-owned module list.
 *
 * Preserve every other directive and the existing newline convention.
 * @license GPL-3.0-only
 */

function pmssProftpdTlsModuleContent(string $contents): string
{
    // In CRLF files the carriage return precedes the multiline end anchor.
    if (preg_match('/^\h*LoadModule\h+mod_tls\.c(?=\h|\r?$)/im', $contents)) {
        return $contents;
    }

    $updated = preg_replace('/^(\h*)#\h*(LoadModule\h+mod_tls\.c)(?=\h|\r?$)/im', '$1$2', $contents, 1, $count);
    if ($count > 0) {
        return $updated;
    }

    $newline = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
    return $contents.(($contents !== '' && !preg_match('/\R$/', $contents)) ? $newline : '').'LoadModule mod_tls.c'.$newline;
}
