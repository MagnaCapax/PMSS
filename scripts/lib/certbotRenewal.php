<?php
/**
 * Certbot renewal log parsing helpers.
 *
 * This module is intentionally small and conservative: it only parses the
 * renewal summary messages emitted by certbot into /var/log/letsencrypt.
 *
 * PHP compatibility: 7.3+
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Parse a certbot log line timestamp (best-effort).
 *
 * Certbot typically prefixes log lines with:
 *   YYYY-MM-DD HH:MM:SS,mmm:LEVEL:...
 *
 * @return int|null Unix timestamp (local time), or null when unparsable.
 */
function pmssCertbotParseLogTimestamp(string $line): ?int
{
    if (!preg_match('/^(\\d{4}-\\d{2}-\\d{2})[ T](\\d{2}:\\d{2}:\\d{2})/', $line, $m)) {
        return null;
    }
    $ts = strtotime($m[1].' '.$m[2]);
    return $ts === false ? null : (int)$ts;
}

/**
 * Summarize certbot renew status from log content.
 *
 * @return array{status:string,event_ts:int|null,event:string,error_excerpt:string}
 */
function pmssCertbotRenewalSummaryFromLog(string $log, int $maxErrorLines = 12): array
{
    $lines = preg_split("/\\r?\\n/", $log) ?: [];
    // Only the last summary is consumed; retain its source index for the excerpt.
    $last = null;
    $count = count($lines);
    foreach ($lines as $i => $line) {
        $lower = strtolower($line);

        if (strpos($lower, 'all renewals succeeded') !== false) {
            $type = 'ok';
        } elseif (strpos($lower, 'no renewals were attempted') !== false) {
            $type = 'noop';
        } elseif (strpos($lower, 'all renewals failed') !== false || strpos($lower, 'some renewals have failed') !== false
            || strpos($lower, 'failed to renew certificate') !== false) {
            // Include per-cert failures from versions without a global summary.
            $type = 'fail';
        } else {
            continue;
        }

        $last = [
            'idx'  => $i,
            'ts'   => pmssCertbotParseLogTimestamp($line),
            'type' => $type,
            'line' => trim($line),
        ];
    }

    if ($last === null) {
        return [
            'status'        => 'unknown',
            'event_ts'      => null,
            'event'         => 'No renewal summary markers found in log',
            'error_excerpt' => '',
        ];
    }

    $excerpt = '';
    if ($last['type'] === 'fail') {
        $start = $last['idx'];
        $buf = [];
        for ($j = $start; $j < $count; $j++) {
            $l = rtrim((string)$lines[$j]);
            if ($l === '') {
                continue;
            }
            // Stop once we hit the next timestamped certbot entry.
            if ($j > $start && pmssCertbotParseLogTimestamp($l) !== null) {
                break;
            }
            $buf[] = $l;
            if (count($buf) >= $maxErrorLines) {
                break;
            }
        }
        $excerpt = implode("\n", $buf);
    }

    return [
        'status'        => $last['type'],
        'event_ts'      => $last['ts'],
        'event'         => $last['line'] !== '' ? $last['line'] : 'Renewal summary marker found',
        'error_excerpt' => $excerpt,
    ];
}

/**
 * Load a certbot log file and summarize renewal status.
 *
 * @return array{status:string,event_ts:int|null,event:string,error_excerpt:string}
 */
function pmssCertbotRenewalSummaryFromFile(string $path, int $maxBytes = 262144): array
{
    if (!is_readable($path)) {
        return [
            'status'        => 'unknown',
            'event_ts'      => null,
            'event'         => 'Log file not readable: '.$path,
            'error_excerpt' => '',
        ];
    }

    // A nonpositive budget cannot yield log data, even if stat fails.
    if ($maxBytes <= 0) {
        return pmssCertbotRenewalSummaryFromLog('');
    }

    $size = @filesize($path);
    if ($size !== false && $size > $maxBytes) {
        // Read only the tail to keep runtime bounded on long-lived hosts.
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return [
                'status'        => 'unknown',
                'event_ts'      => null,
                'event'         => 'Failed to open log file: '.$path,
                'error_excerpt' => '',
            ];
        }
        try {
            $offset = max(0, (int)$size - $maxBytes);
            // A failed seek must not turn a tail read into an unbounded full read.
            if (@fseek($fp, $offset) !== 0) {
                return pmssCertbotRenewalSummaryFromLog('');
            }
            $data = stream_get_contents($fp, max(0, $maxBytes));
        } finally {
            // Preserve read/seek exceptions while always releasing the log stream.
            fclose($fp);
        }
    } else {
        // Stat may fail or become stale while the log grows; bound this read too.
        $data = @file_get_contents($path, false, null, 0, $maxBytes);
    }
    return pmssCertbotRenewalSummaryFromLog(is_string($data) ? $data : '');
}

