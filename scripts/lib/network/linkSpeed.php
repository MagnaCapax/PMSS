<?php
/** Physical link-speed observation without configured shaping caps. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/interface.php';

/** Read sysfs first, then ethtool; an explicit sysfs fixture never probes the host. */
function getDetectedLinkSpeed(string $iface): int
{
    $iface = pmssNetworkInterfaceNameNormalize($iface);
    if ($iface === '') return 0;

    $baseDir = getenv('PMSS_NETWORK_SYS_CLASS_NET_DIR');
    $baseDir = is_string($baseDir) && trim($baseDir) !== '' && strpos($baseDir, "\0") === false
        ? $baseDir : null;
    $raw = @file_get_contents(rtrim($baseDir ?? '/sys/class/net', '/').'/'.$iface.'/speed');
    // Oversized probe values remain unknown; zero and negative speeds cannot supply capacity.
    $speed = pmssUnsignedDecimalIntParse(is_string($raw) ? trim($raw) : '');
    if (($speed !== null && $speed > 0) || $baseDir !== null) return $speed ?? 0;

    $raw = shell_exec('/sbin/ethtool '.escapeshellarg($iface).' 2>/dev/null');
    if (!$raw || !preg_match('/Speed:\s*(\d+)Mb/', $raw, $m)) return 0;
    return pmssUnsignedDecimalIntParse($m[1]) ?? 0;
}
