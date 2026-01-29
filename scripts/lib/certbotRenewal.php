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
    $lines = preg_split("/\\r?\\n/", $log);
    if (!is_array($lines)) {
        $lines = [];
    }

    $events = [];
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $line = (string)$lines[$i];
        $lower = strtolower($line);

        $type = null;
        if (strpos($lower, 'all renewals succeeded') !== false) {
            $type = 'ok';
        } elseif (strpos($lower, 'no renewals were attempted') !== false) {
            $type = 'noop';
        } elseif (strpos($lower, 'all renewals failed') !== false || strpos($lower, 'some renewals have failed') !== false) {
            $type = 'fail';
        } elseif (strpos($lower, 'failed to renew certificate') !== false) {
            // Some certbot versions log per-cert failures without the global summary line.
            $type = 'fail';
        }

        if ($type === null) {
            continue;
        }

        $events[] = [
            'idx'  => $i,
            'ts'   => pmssCertbotParseLogTimestamp($line),
            'type' => $type,
            'line' => trim($line),
        ];
    }

    if (empty($events)) {
        return [
            'status'        => 'unknown',
            'event_ts'      => null,
            'event'         => 'No renewal summary markers found in log',
            'error_excerpt' => '',
        ];
    }

    $last = $events[count($events) - 1];
    $status = (string)$last['type'];
    $eventTs = is_int($last['ts']) ? $last['ts'] : null;
    $eventLine = (string)$last['line'];

    $excerpt = '';
    if ($status === 'fail') {
        $start = (int)$last['idx'];
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
        'status'        => $status,
        'event_ts'      => $eventTs,
        'event'         => $eventLine !== '' ? $eventLine : 'Renewal summary marker found',
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
        $offset = (int)$size - $maxBytes;
        if ($offset < 0) {
            $offset = 0;
        }
        @fseek($fp, $offset);
        $data = stream_get_contents($fp);
        fclose($fp);
        if (!is_string($data)) {
            $data = '';
        }
        return pmssCertbotRenewalSummaryFromLog($data);
    }

    $data = @file_get_contents($path);
    if (!is_string($data)) {
        $data = '';
    }
    return pmssCertbotRenewalSummaryFromLog($data);
}

