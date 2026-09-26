#!/usr/bin/env php
<?php
/**
 * Opt-in ARP keepalive, one datagram per run (ADR 0069). Inert unless
 * /etc/seedbox/config/arp-keepalive holds an unused on-link IPv4 address.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/arpKeepalive.php';

$line = pmssArpKeepaliveRun(
    PMSS_ARP_KEEPALIVE_MARKER,
    static function (string $ip): string {
        return (string)shell_exec('ip -4 route get '.escapeshellarg($ip).' 2>/dev/null');
    },
    static function (): string {
        return (string)@file_get_contents('/proc/net/arp');
    },
    'pmssArpKeepaliveSend'
);
if ($line !== '') {
    echo $line, "\n";
}
