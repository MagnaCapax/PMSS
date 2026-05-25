<?php
/**
 * Customer-side traffic helpers.
 *
 * Reads ~/.trafficLimit and ~/.bonusTraffic (both customer-owned files) and
 * returns a state array consumed by welcome.php and stats.php. Lives in
 * etc/skel/www/ because customer PHP runs as the customer UID and cannot
 * traverse /scripts/ (the operator-only tree). The companion operator-side
 * write logic remains in scripts/lib/user/trafficLimit.php — that file is
 * required by /scripts/util/userTrafficLimit.php (the operator CLI) and by
 * /scripts/cron/trafficLimits.php (the cron enforcer). Both writes the
 * customer-readable files that THIS reader consumes.
 *
 * Inlined limit functions (subset of /scripts/lib/user/trafficLimit.php +
 * /scripts/lib/user/integerSetting.php + /scripts/lib/runtime.php):
 *   pmssTrafficLimitStateRead, pmssTrafficLimitReadGiBFile,
 *   pmssTrafficLimitParseGiB.
 * The chained operator-tree helpers were inlined here as the customer-side
 * minimal subset; no functional difference for the welcome/stats call sites.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssTrafficLimitParseGiB')) {
    /** Parse "<integer>", "<integer>GiB" or null; return non-negative int or null. */
    function pmssTrafficLimitParseGiB($raw, ?string &$error = null): ?int
    {
        $error = null;
        if ($raw === null || $raw === false || $raw === true) {
            $error = 'missing';
            return null;
        }

        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                $error = 'empty';
                return null;
            }
            if (!preg_match('/^([0-9]+)(?:\s*GiB)?$/i', $trim, $matches)) {
                $error = 'invalid format';
                return null;
            }
            $value = (int) $matches[1];
        } elseif (is_float($raw) && floor($raw) == $raw) {
            $value = (int) $raw;
        } else {
            $error = 'invalid type';
            return null;
        }

        if ($value < 0) {
            $error = 'negative';
            return null;
        }
        return $value;
    }
}

if (!function_exists('pmssTrafficLimitReadGiBFile')) {
    /** Read a GiB quota file as a non-negative int; 0 if missing/unreadable/invalid. */
    function pmssTrafficLimitReadGiBFile(string $path): int
    {
        if ($path === '' || !is_file($path) || is_link($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        $trim = trim($raw);
        if ($trim === '') {
            return 0;
        }
        $value = pmssTrafficLimitParseGiB($trim);
        return $value !== null ? $value : 0;
    }
}

if (!function_exists('pmssTrafficLimitStateRead')) {
    /**
     * Build the traffic-limit state array used by welcome.php / stats.php.
     *
     * @return array{limitGiB:int,bonusGiB:int,effectiveLimitGiB:int}
     */
    function pmssTrafficLimitStateRead(string $limitPath, string $bonusPath = ''): array
    {
        $limitGiB = pmssTrafficLimitReadGiBFile($limitPath);
        $bonusGiB = ($bonusPath !== '') ? pmssTrafficLimitReadGiBFile($bonusPath) : 0;
        return [
            'limitGiB'          => $limitGiB,
            'bonusGiB'          => $bonusGiB,
            'effectiveLimitGiB' => ($limitGiB > 0) ? ($limitGiB + $bonusGiB) : 0,
        ];
    }
}

if (!function_exists('pmssTrafficRatioStateBuild')) {
    /** Classify monthly inbound:outbound ratio for welcome.php and stats.php.
     * @return array{available:bool,display:string,class:string,color:string} */
    function pmssTrafficRatioStateBuild($outboundMonth, $inboundMonth): array
    {
        if (!is_numeric($outboundMonth) || !is_numeric($inboundMonth)) {
            return ['available' => false, 'display' => '', 'class' => '', 'color' => ''];
        }
        $outboundMonth = (float) $outboundMonth;
        if ($outboundMonth <= 0) {
            return ['available' => true, 'display' => 'N/A', 'class' => 'na', 'color' => '#b0bec5'];
        }
        $ratio = (float) $inboundMonth / $outboundMonth;
        $state = $ratio >= 2.0
            ? array('good', '#81c784')
            : ($ratio >= 1.0 ? array('warn', '#ffb74d') : array('bad', '#ef5350'));

        return array(
            'available' => true,
            'display'   => number_format($ratio, 2).':1',
            'class'     => $state[0],
            'color'     => $state[1],
        );
    }
}
