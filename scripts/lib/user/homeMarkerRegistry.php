<?php
/**
 * Registry of the per-user home marker files writeHomeMarker.php writes (ADR 0046, ADR 0213).
 *
 * Every marker is a display copy: it exists so the account's panel and its own tools can show
 * the value. Root jobs never read these files; they read the root store
 * (/etc/seedbox/config/users/<user>.json) under the key named here. writeHomeMarker.php writes
 * that key first and the display copy second. A marker without a store key is the display copy
 * of a value root keeps elsewhere (disk quota via setquota, traffic limit via userTrafficLimit.php).
 *
 * Owner is always root; group is the user (the read channel).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

if (!function_exists('pmssHomeMarkerRegistry')) {
    /**
     * @return array<string,array{class:string,mode:int,store:?string,max?:int,type?:string}> keyed by bare marker filename
     */
    function pmssHomeMarkerRegistry(): array
    {
        return [
            '.bonus'            => ['class' => 'display', 'mode' => 0640, 'store' => 'bonusPercent', 'max' => 300],
            '.bonusTraffic'     => ['class' => 'display', 'mode' => 0640, 'store' => 'bonusTrafficGiB'],
            '.billingId'        => ['class' => 'display', 'mode' => 0640, 'store' => 'billingServiceId'],
            '.billingServiceId' => ['class' => 'display', 'mode' => 0640, 'store' => 'billingServiceId'],
            '.billingClientId'  => ['class' => 'display', 'mode' => 0640, 'store' => 'billingClientId'],
            '.notifyEmail'      => ['class' => 'display', 'mode' => 0640, 'store' => 'notificationEmail', 'type' => 'email'],
            '.bonusQuota'       => ['class' => 'display', 'mode' => 0644, 'store' => null],
            '.trafficLimit'     => ['class' => 'display', 'mode' => 0644, 'store' => null],
        ];
    }
}

if (!function_exists('pmssHomeMarkerIsKnown')) {
    function pmssHomeMarkerIsKnown(string $marker): bool
    {
        return array_key_exists($marker, pmssHomeMarkerRegistry());
    }
}

if (!function_exists('pmssHomeMarkerMode')) {
    /** Mode for a known marker, or null when the marker is not in the registry. */
    function pmssHomeMarkerMode(string $marker): ?int
    {
        $reg = pmssHomeMarkerRegistry();
        return array_key_exists($marker, $reg) ? (int) $reg[$marker]['mode'] : null;
    }
}

if (!function_exists('pmssHomeMarkerClass')) {
    /** Class for a known marker (always "display"), or null when not in the registry. */
    function pmssHomeMarkerClass(string $marker): ?string
    {
        $reg = pmssHomeMarkerRegistry();
        return array_key_exists($marker, $reg) ? (string) $reg[$marker]['class'] : null;
    }
}

if (!function_exists('pmssHomeMarkerValueParse')) {
    /** Accept only a non-negative decimal integer representable by this PHP runtime. */
    function pmssHomeMarkerValueParse(string $raw): ?int
    {
        return pmssUnsignedDecimalIntParse($raw);
    }
}
