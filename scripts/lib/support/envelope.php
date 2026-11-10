<?php
/** Pure support mail envelope validation and rendering. @license GPL-3.0-only */

/**
 * Normalize a hostname/token used in SMTP commands and envelope headers.
 */
function pmssSupportMailHostTokenNormalize(string $value, string $fallback): string
{
    $normalized = preg_replace('/[^A-Za-z0-9.-]/', '-', $value);
    $normalized = trim(is_string($normalized) ? $normalized : '', '-.');

    return $normalized === '' ? $fallback : $normalized;
}

/**
 * Validate and normalize the transport-facing mail envelope.
 *
 * @return array<string,string>
 */
function pmssSupportMailEnvelopeNormalize(array $envelope): array
{
    $normalized = [];
    foreach (['from' => 'sender', 'to' => 'recipient'] as $key => $label) {
        $normalized[$key] = trim((string) ($envelope[$key] ?? ''));
        if ($normalized[$key] === '' || preg_match('/[\r\n\x00]/', $normalized[$key]) === 1) {
            throw new RuntimeException('Support mail envelope '.$label.' is invalid.');
        }
    }
    $normalized['data'] = (string) ($envelope['data'] ?? '');
    if ($normalized['data'] === '') {
        throw new RuntimeException('Support mail envelope payload is empty.');
    }
    return $normalized;
}

/**
 * Build the outbound message envelope for a support request.
 *
 * @return array<string,string>
 */
function pmssSupportMailEnvelopeBuild(array $diagnostics, array $config, string $snapshotPath): array
{
    $subject = sprintf(
        '[PMSS Support] billing_service=%d user=%s host=%s',
        (int) ($diagnostics['billingServiceId'] ?? 0),
        (string) ($diagnostics['username'] ?? 'unknown-user'),
        (string) ($diagnostics['hostname'] ?? 'unknown-host')
    );
    $senderHost = pmssSupportMailHostTokenNormalize((string) ($diagnostics['hostname'] ?? 'pmss.local'), 'pmss.local');
    $from = 'support-command@'.$senderHost;
    $headers = [
        'From: '.$from,
        'To: '.$config['targetEmail'],
        'Subject: '.$subject,
        'X-PMSS-Username: '.(string) ($diagnostics['username'] ?? ''),
        'X-PMSS-Billing-Service-Id: '.(string) ((int) ($diagnostics['billingServiceId'] ?? 0)),
        'X-PMSS-Billing-Client-Id: '.(string) ((int) ($diagnostics['billingClientId'] ?? 0)),
        'X-PMSS-Hostname: '.(string) ($diagnostics['hostname'] ?? ''),
        'Content-Type: text/plain; charset=UTF-8',
    ];

    return [
        'from' => $from,
        'to' => (string) $config['targetEmail'],
        'data' => implode("\r\n", $headers)."\r\n\r\n"
            .'Snapshot: '.$snapshotPath."\r\n\r\n"
            .str_replace("\n", "\r\n", (string) ($diagnostics['body'] ?? ''))."\r\n",
    ];
}
