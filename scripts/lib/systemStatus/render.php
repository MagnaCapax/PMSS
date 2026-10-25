<?php
/** Pure status rows, summaries, and CLI text/JSON rendering.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/cli/helpText.php';

/** Build a normalized status entry for text and JSON outputs. */
function pmssStatus(string $name, string $status, string $detail = ''): array
{
    return ['name' => $name, 'status' => $status, 'detail' => $detail];
}

/** Count OK/WARN/ERR entries for summary banners and JSON payloads. */
function pmssStatusSummary(array $checks): array
{
    $summary = ['ok' => 0, 'warn' => 0, 'err' => 0];
    foreach ($checks as $check) {
        $status = is_array($check) ? (string) ($check['status'] ?? '') : '';
        // The legacy summary counts every status other than exact WARN/ERR as OK.
        $summary[$status === 'ERR' ? 'err' : ($status === 'WARN' ? 'warn' : 'ok')]++;
    }
    return $summary;
}
/** Emit either JSON or text output for a PMSS status report. */
function pmssStatusEmit(
    array $checks,
    string $title,
    bool $wantJson,
    array $jsonPayload,
    ?array $summary = null,
    int $jsonFlags = 0,
    bool $useColour = false,
    int $labelWidth = 8,
    bool $leadingNewline = true
): int
{
    if ($wantJson) {
        return pmssJsonEmitPayload($jsonPayload, 'Failed to encode status JSON.', $jsonFlags);
    }

    $summary = $summary ?? pmssStatusSummary($checks);
    echo ($leadingNewline ? "\n" : '').$title.' ('.date('Y-m-d H:i:s').")\n";
    echo str_repeat('-', 60)."\n";

    $isTty = $useColour && pmssStreamIsTty(STDOUT, true);
    $textValue = static function ($value): string {
        return is_scalar($value) ? (string) $value : '';
    };
    foreach ($checks as $result) {
        $status = strtoupper($textValue($result['status'] ?? ''));
        $label = str_pad('['.$status.']', $labelWidth);
        $name = $textValue($result['name'] ?? '');
        $detail = $textValue($result['detail'] ?? '');
        $code = ['OK' => '32', 'WARN' => '33', 'ERR' => '31'][$status] ?? '';
        echo pmssCliAnsiStyle($label, $code, $isTty && $code !== '').$name.($detail !== '' ? ' - '.$detail : '').PHP_EOL;
    }

    echo str_repeat('-', 60)."\n";
    printf(
        "Summary: %d OK, %d WARN, %d ERR\n",
        (int) ($summary['ok'] ?? 0),
        (int) ($summary['warn'] ?? 0),
        (int) ($summary['err'] ?? 0)
    );
    return 0;
}

