<?php
/**
 * Live stats block for the customer panel: node total versus your own share.
 *
 * Averages for 15 min / 1 h / 24 h plus a 24 h trend as inline SVG (no JavaScript,
 * no polling). Reads only the files written by root cron (liveStatsLog.php);
 * customers see the node total and their own numbers, never other accounts. ADR 0126.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

if (!function_exists('pmssStatsLiveRingRead')) {
    /** Samples of a ring file; [] when absent, a symlink or malformed. */
    function pmssStatsLiveRingRead($path) {
        $d = is_link($path) || !is_file($path) ? null : json_decode((string) @file_get_contents($path), true);
        return is_array($d) && is_array($d['samples'] ?? null) ? $d['samples'] : array();
    }
}

if (!function_exists('pmssStatsLiveRows')) {
    /** Node samples of the last $window seconds joined with this user's sample of the same slot. */
    function pmssStatsLiveRows(array $node, array $mine, $now, $window) {
        $byTime = array();
        foreach ($mine as $u) {
            if (is_array($u) && isset($u['t'])) $byTime[(int) $u['t']] = $u;
        }
        $rows = array();
        foreach ($node as $n) {
            if (!is_array($n) || !isset($n['t'], $n['dt'], $n['ct'], $n['cb'], $n['mu'], $n['mt'], $n['tx'], $n['rx']) || $n['t'] <= $now - $window || $n['t'] > $now) continue;
            $rows[] = array('node' => $n, 'user' => isset($byTime[(int) $n['t']]) ? $byTime[(int) $n['t']] : array());
        }
        return $rows;
    }
}

if (!function_exists('pmssStatsLiveSummary')) {
    /** Average CPU/RAM percent and network Mbit/s for node and user; null without rows. */
    function pmssStatsLiveSummary(array $rows) {
        if ($rows === array()) return null;
        $s = array_fill_keys(array('ct', 'cb', 'cu', 'dt', 'rn', 'ru', 'tx', 'rx', 'utx', 'urx'), 0.0);
        foreach ($rows as $r) {
            $n = $r['node'];
            $u = $r['user'] + array('cpu' => 0, 'mem' => 0, 'tx' => 0, 'rx' => 0);
            $mt = max(1.0, (float) $n['mt']);
            $s['ct'] += $n['ct']; $s['cb'] += $n['cb']; $s['cu'] += $u['cpu']; $s['dt'] += $n['dt'];
            $s['rn'] += $n['mu'] / $mt; $s['ru'] += $u['mem'] / $mt;
            $s['tx'] += $n['tx']; $s['rx'] += $n['rx']; $s['utx'] += $u['tx']; $s['urx'] += $u['rx'];
        }
        $c = count($rows); $ct = max(1.0, $s['ct']); $dt = max(1.0, $s['dt']);
        return array('cpu_node' => min(100.0, $s['cb'] / $ct * 100), 'cpu_you' => min(100.0, $s['cu'] / $ct * 100),
            'ram_node' => min(100.0, $s['rn'] / $c * 100), 'ram_you' => min(100.0, $s['ru'] / $c * 100),
            'tx_node' => $s['tx'] * 8 / $dt / 1e6, 'tx_you' => $s['utx'] * 8 / $dt / 1e6,
            'rx_node' => $s['rx'] * 8 / $dt / 1e6, 'rx_you' => $s['urx'] * 8 / $dt / 1e6);
    }
}

if (!function_exists('pmssStatsLiveSeries')) {
    /** Rows averaged into at most $points buckets of [node, you] per metric. */
    function pmssStatsLiveSeries(array $rows, $points) {
        $out = array('cpu' => array(), 'ram' => array(), 'tx' => array(), 'rx' => array());
        foreach ($rows === array() ? array() : array_chunk($rows, max(1, (int) ceil(count($rows) / $points))) as $chunk) {
            $s = pmssStatsLiveSummary($chunk);
            foreach ($out as $k => $unused) $out[$k][] = array($s[$k.'_node'], $s[$k.'_you']);
        }
        return $out;
    }
}

if (!function_exists('pmssStatsLiveRender')) {
    /** Print the block; a short notice until the first samples exist. */
    function pmssStatsLiveRender() {
        $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $node = pmssStatsLiveRingRead('/var/lib/pmss/public/node-live.json');
        $mine = is_array($account) ? pmssStatsLiveRingRead('/var/lib/pmss/live/'.$account['name'].'.json') : array();
        echo '<style>.live-table{width:100%;border-collapse:collapse;font-size:.9em}.live-table th{text-align:left;color:#81c784;font-weight:500;padding:6px 8px}'
            .'.live-table td{padding:6px 8px;vertical-align:top;min-width:130px}.live-bar{height:10px;background:#333;border-radius:5px;overflow:hidden}'
            .'.live-bar span{display:block;height:100%}.live-bar .live-node,.live-svg .live-node{background:#9fb8c8;stroke:#9fb8c8}.live-bar .live-you,.live-svg .live-you{background:#1e88e5;stroke:#1e88e5;stroke-width:2}'
            .'.live-txt{white-space:nowrap;margin-top:3px;color:#b0bec5;font-size:.85em}.live-svg{width:100%;height:40px}.live-svg polyline{fill:none;stroke-width:1.5}'
            .'.live-k-node{color:#9fb8c8}.live-k-you{color:#42a5f5;font-weight:600}</style>';
        echo '<div class="stats-block live-stats"><h6>Node load vs. your share</h6>';
        if ($node === array()) {
            echo '<pre>Live statistics are being collected. Check back in about 10 minutes.</pre></div>';
            return;
        }
        $now = (int) end($node)['t'];
        $windows = array('15 min' => 900, '1 hour' => 3600, '24 hours' => 86400);
        $metrics = array('CPU' => array('cpu', '%'), 'RAM' => array('ram', '%'), 'Network out' => array('tx', ' Mbit/s'), 'Network in' => array('rx', ' Mbit/s'));
        $all = pmssStatsLiveRows($node, $mine, $now, 86400);
        $series = pmssStatsLiveSeries($all, 96);
        echo '<table class="live-table"><tr><th></th>';
        foreach ($windows as $label => $seconds) echo '<th>'.$label.'</th>';
        echo '<th>24 h trend</th></tr>';
        foreach ($metrics as $name => $m) {
            echo '<tr><th>'.$name.'</th>';
            foreach ($windows as $seconds) {
                $s = pmssStatsLiveSummary(pmssStatsLiveRows($node, $mine, $now, $seconds));
                echo $s === null ? '<td>n/a</td>' : pmssStatsLiveCell($s[$m[0].'_node'], $s[$m[0].'_you'], $m[1]);
            }
            $max = $m[1] === '%' ? 100 : max(1, max(array_map(function ($p) { return $p[0]; }, $series[$m[0]] ?: array(array(1, 0)))));
            echo '<td>'.pmssStatsLiveSvg($series[$m[0]], $max).'</td></tr>';
        }
        echo '</table><div class="memory-pressure-note"><span class="live-k-node">&#9632; node</span> = whole server &nbsp; '
            .'<span class="live-k-you">&#9632; you</span> = your own usage. Averages of 5-minute samples; you only see the node total and your own numbers.</div></div>';
    }
}

if (!function_exists('pmssStatsLiveCell')) {
    /** Table cell: share bar (you inside node) and the two values. */
    function pmssStatsLiveCell($node, $you, $unit) {
        $fmt = function ($v) use ($unit) { return number_format($v, $v < 10 ? 1 : 0, '.', '').$unit; };
        return '<td><div class="live-bar"><span class="live-node" style="width:'.($unit === '%' ? round($node) : 100).'%"><span class="live-you" style="width:'
            .round($node > 0 ? min(100, $you / $node * 100) : 0).'%"></span></span></div><div class="live-txt"><span class="live-k-node">node '
            .$fmt($node).'</span> &middot; <span class="live-k-you">you '.$fmt($you).'</span></div></td>';
    }
}

if (!function_exists('pmssStatsLiveSvg')) {
    /** Two-line trend (node, you) scaled to $max as inline SVG. */
    function pmssStatsLiveSvg(array $points, $max) {
        $n = count($points);
        $lines = '';
        foreach ($n < 2 ? array() : array('live-node', 'live-you') as $i => $class) {
            $c = array();
            foreach ($points as $x => $p) $c[] = round($x * 300 / ($n - 1), 1).','.round(38 - min(1, $p[$i] / $max) * 36, 1);
            $lines .= '<polyline class="'.$class.'" points="'.implode(' ', $c).'"/>';
        }
        return '<svg class="live-svg" viewBox="0 0 300 40" preserveAspectRatio="none" role="img">'.$lines.'</svg>';
    }
}
