<?php
/** WireGuard peer block parsing and configuration text rendering. @license GPL-3.0-only */

/** Parse PMSS-managed peer blocks from wg0.conf lines. */
function wgConfigPeerBlocksFromLines(array $lines): array
{
    $peers = [];
    $current = ['user' => '', 'key' => '', 'ip' => ''];
    // Owner markers remain case-sensitive; daemon field names do not.
    $patterns = [
        'user' => '/^#\s*user\s*=\s*(.*?)\s*$/',
        'key' => '/^PublicKey\s*=\s*(.*?)\s*$/i',
        'ip' => '/^AllowedIPs\s*=\s*(.*?)\s*$/i',
    ];

    // PMSS's # user markers are data, so retain comment-prefixed lines here.
    foreach (pmssConfigLines(array_merge($lines, ['[Peer]']), []) as $trimmed) {
        if (stripos($trimmed, '[Peer]') === 0) {
            if ($current['user'] !== '' || $current['key'] !== '' || $current['ip'] !== '') {
                $peers[] = $current;
            }
            $current = ['user' => '', 'key' => '', 'ip' => ''];
            continue;
        }
        foreach ($patterns as $field => $pattern) {
            if (preg_match($pattern, $trimmed, $matches) !== 1) continue;
            $value = trim($matches[1]);
            // Only the first AllowedIPs address is part of the managed peer record.
            $current[$field] = $field === 'ip'
                ? trim(explode('/', trim(explode(',', $value, 2)[0]), 2)[0]) : $value;
            break;
        }
    }

    return $peers;
}

/**
 * Render auto-managed peer sections from collected public keys.
 */
function wgBuildPeersConfig(?array $peerState = null): string
{
    $peerState = $peerState ?? wgPeerState();
    if (!$peerState['entriesFound']) {
        return "# No WireGuard peers configured; place public key(s) in ~/.wireguard-public-key on each user account.\n";
    }

    if (empty($peerState['assigned'])) {
        return "# No valid WireGuard peers configured; all provided keys were invalid.\n";
    }

    $config = "# Peers managed by PMSS – do not edit by hand.\n";
    foreach ($peerState['assigned'] as $entry) {
        $config .= "[Peer]\n# user=".$entry['user']."\nPublicKey = ".$entry['key']
            ."\nAllowedIPs = ".$entry['ip']."/32\n\n";
    }
    return rtrim($config)."\n";
}
