<?php
/**
 * Pure rTorrent template rendering; callers supply defaults and reserved ports.
 *
 * @license GPL-3.0-only
 */

/** Render the legacy tokens without acquiring ports or reading host files. */
function pmssRtorrentConfigRender(string $template, array $config, array $resources): string
{
    $blocks = round(($config['ram'] / $resources['ramBlock']), 2);
    $uploadSlots = floor($resources['uploadSlots'] * $blocks);
    $ramMiB = max(0, (int) $config['ram']);
    $gapMiB = max(250, min(1000, (int) floor($ramMiB * 0.25)));
    $uploadThrottleLine = '';
    if (isset($config['uploadThrottle']) && is_numeric($config['uploadThrottle'])) {
        $uploadThrottle = (int) $config['uploadThrottle'];
        $uploadThrottleLine = $uploadThrottle > 0 ? 'throttle.global_up.max_rate.set = '.$uploadThrottle : '';
    }

    // Global slots must precede their shared token prefix in the replacement pass.
    $replacements = [
        '##minimumPeers' => ceil($resources['peers']['minimum'] * $blocks),
        '##maximumPeers' => floor($resources['peers']['maximum'] * $blocks),
        '##uploadSlotsGlobal' => $uploadSlots * 6,
        '##uploadSlots' => $uploadSlots,
        '##uploadThrottleLine' => $uploadThrottleLine,
        '##scgiPort' => $config['scgiPort'],
        '##dhtPort' => $config['dhtPort'],
        '##listenPort' => $config['listenPort'],
        '##pex' => $config['pex'],
        '##dht' => $config['dht'],
        '##memoryMax' => max(170, $ramMiB - $gapMiB).'M',
    ];
    return str_replace(array_keys($replacements), $replacements, $template);
}
