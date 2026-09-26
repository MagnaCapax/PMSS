<?php
/**
 * Opt-in ARP keepalive (ADR 0069).
 *
 * Some upstream routers drop a host's ARP entry on a fixed age and then fail to
 * re-resolve it for minutes, blackholing inbound traffic. Sending one datagram to an
 * unused on-link address makes the kernel broadcast an ARP request carrying this
 * host's own address, which keeps the router's entry for the host fresh.
 *
 * Inert unless the marker file names a target: one IPv4 address, on-link, that
 * nothing answers. Never touches configuration; one datagram per run.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_ARP_KEEPALIVE_MARKER = '/etc/seedbox/config/arp-keepalive';

/** Read and validate the target from the marker. Null = feature off or invalid. */
function pmssArpKeepaliveTarget(string $markerPath, ?string &$error = null): ?string
{
    $error = null;
    if (!is_file($markerPath)) {
        return null;
    }
    $raw = trim((string)@file_get_contents($markerPath, false, null, 0, 64));
    if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $error = 'marker does not hold a single IPv4 address';
        return null;
    }
    return $raw;
}

/** True when the kernel would reach $ip directly (no gateway hop). */
function pmssArpKeepaliveIsOnLink(string $routeGetOutput): bool
{
    return $routeGetOutput !== '' && strpos($routeGetOutput, ' via ') === false;
}

/**
 * Neighbour state for $ip from /proc/net/arp content: 'incomplete' (flags 0x0,
 * nothing answered), 'complete' (something answers), or 'absent'.
 */
function pmssArpKeepaliveNeighbourState(string $procNetArp, string $ip): string
{
    foreach (preg_split('/\R/', $procNetArp) ?: [] as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (count($cols) >= 4 && $cols[0] === $ip) {
            return $cols[2] === '0x0' ? 'incomplete' : 'complete';
        }
    }
    return 'absent';
}

/** Send one UDP datagram to $ip:9 (discard). Returns true when it was handed to the kernel. */
function pmssArpKeepaliveSend(string $ip): bool
{
    $sock = @stream_socket_client('udp://'.$ip.':9', $errno, $errstr, 1);
    if ($sock === false) {
        return false;
    }
    $ok = @fwrite($sock, 'k') === 1;
    fclose($sock);
    return $ok;
}

/** One run: returns a single log line (never throws). */
function pmssArpKeepaliveRun(string $markerPath, callable $routeGet, callable $readArp, callable $send): string
{
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    $target = pmssArpKeepaliveTarget($markerPath, $error);
    if ($target === null) {
        return $error === null ? '' : "$ts arp-keepalive skip: $error";
    }
    if (!pmssArpKeepaliveIsOnLink((string)$routeGet($target))) {
        return "$ts arp-keepalive skip: target $target is not on-link";
    }
    $before = pmssArpKeepaliveNeighbourState((string)$readArp(), $target);
    if ($before === 'complete') {
        return "$ts arp-keepalive WARN: target $target answers; choose an unused address";
    }
    $sent = $send($target) ? 'sent' : 'send-failed';
    return "$ts arp-keepalive $sent target=$target neighbour=$before";
}
