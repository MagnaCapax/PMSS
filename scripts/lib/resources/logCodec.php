<?php
/** Pure resource text-record schema, serialization and parsing. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/config.php';

const PMSS_RESOURCE_LOG_DELTA_FIELDS = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'];
const PMSS_RESOURCE_LOG_CURRENT_FIELDS = ['memory', 'tasks'];

/** @return array<string, string> */
function pmssResourceMemoryBreakdownFieldMap(string $prefix = 'memory_'): array { return ['anon' => $prefix.'anon', 'file' => $prefix.'file']; }

/** Compose the stable resource cron text-record payload after the timestamp. */
function pmssResourceLogLineParts(array $delta, array $state): array
{
    $parts = [];
    foreach (PMSS_RESOURCE_LOG_DELTA_FIELDS as $field) $parts[] = (string) ($delta[$field] ?? 0);
    foreach (PMSS_RESOURCE_LOG_CURRENT_FIELDS as $field) $parts[] = (string) ($state[$field] ?? 0);
    if (isset($state['memory_anon'], $state['memory_file'])) foreach (pmssResourceMemoryBreakdownFieldMap() as $field) $parts[] = (string) $state[$field];
    return $parts;
}

/** Return the payload fields accepted by each historical resource-log line shape. */
function pmssResourceLogPayloadFields(int $payloadCount): ?array
{
    // CPU is the final delta counter; old records omit only the two IO operation fields.
    return ($payloadCount < 5 || $payloadCount === 8) ? null : array_merge(array_slice(PMSS_RESOURCE_LOG_DELTA_FIELDS, 0, $payloadCount >= 7 ? 4 : 2),
        ['cpu'], PMSS_RESOURCE_LOG_CURRENT_FIELDS,
        $payloadCount >= 9 ? array_values(pmssResourceMemoryBreakdownFieldMap()) : []);
}

/** Parse one resource cron text record into the accumulator sample schema. */
function pmssResourceLogLineParse($line)
{
    $tokens = pmssConfigLineColumns((string) $line, 0, []);
    $fields = pmssResourceLogPayloadFields(count($tokens) - 2);
    if ($fields === null || ($timestamp = strtotime($tokens[0].' '.$tokens[1])) === false) return false;

    $parsed = ['timestamp' => (int) $timestamp] + array_fill_keys(pmssResourceLogPayloadFields(7), 0.0);
    foreach ($fields as $offset => $field) {
        $value = $tokens[$offset + 2] ?? '';
        // Digit-only input can still overflow a float; use the existing parse-failure path.
        if (!ctype_digit($value) || !is_finite($parsed[$field] = (float) $value)) return false;
    }
    return $parsed;
}
