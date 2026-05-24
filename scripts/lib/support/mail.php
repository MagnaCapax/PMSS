<?php
/**
 * Support command mail delivery helpers.
 *
 * Delivery prefers a local sendmail-compatible binary when available and falls
 * back to direct MX delivery so the command stays functional on hosts where
 * PMSS has purged exim4.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/diagnostics.php';

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
    $data = (string) ($envelope['data'] ?? '');
    if ($data === '') {
        throw new RuntimeException('Support mail envelope payload is empty.');
    }
    $normalized['data'] = $data;
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
        '[PMSS Support] billing=%d user=%s host=%s',
        (int) ($diagnostics['billingId'] ?? 0),
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
        'X-PMSS-Billing-Id: '.(string) ((int) ($diagnostics['billingId'] ?? 0)),
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

/**
 * Deliver the support mail through the best available transport.
 */
function pmssSupportMailSend(array $config, array $envelope, ?callable $transport = null): void
{
    $envelope = pmssSupportMailEnvelopeNormalize($envelope);

    if ($transport !== null) {
        $transport($config, $envelope);
        return;
    }

    $sendmail = pmssCommandPath('sendmail');
    if ($sendmail !== '') {
        pmssSupportMailSendViaSendmail($sendmail, $envelope);
        return;
    }

    $relayHost = trim((string) ($config['relayHost'] ?? ''));
    if ($relayHost === '') {
        $parts = explode('@', (string) $config['targetEmail']);
        $relayHost = strtolower((string) end($parts));
        $hosts = [];
        if ($relayHost !== '' && function_exists('getmxrr') && @getmxrr($relayHost, $hosts) && !empty($hosts[0])) {
            $relayHost = (string) $hosts[0];
        }
    }

    pmssSupportMailSendViaSmtp($relayHost, (int) $config['smtpPort'], (int) $config['connectTimeout'], $envelope);
}

/**
 * Deliver the support message via a local sendmail-compatible binary.
 */
function pmssSupportMailSendViaSendmail(string $sendmailPath, array $envelope): void
{
    $envelope = pmssSupportMailEnvelopeNormalize($envelope);
    $spec = pmssProcessPipeDescriptorSpec('w');
    $process = @proc_open(escapeshellarg($sendmailPath).' -t -i', $spec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start sendmail transport.');
    }
    if (!isset($pipes[0], $pipes[1], $pipes[2])
        || !is_resource($pipes[0])
        || !is_resource($pipes[1])
        || !is_resource($pipes[2])) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        throw new RuntimeException('Sendmail transport did not provide usable pipes.');
    }

    $stderr = '';
    try {
        pmssSupportStreamWriteAll($pipes[0], (string) $envelope['data'], 'support mail message to sendmail');
        fclose($pipes[0]);
        unset($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        if (!is_string($stderr)) {
            $stderr = '';
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        unset($pipes[1], $pipes[2]);

        $rc = proc_close($process);
        $process = null;
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    if ($rc !== 0) {
        throw new RuntimeException('Support mail delivery failed: '.trim((string) $stderr));
    }
}

/**
 * Deliver the support message directly to the recipient MX via SMTP.
 */
function pmssSupportMailSendViaSmtp(string $host, int $port, int $timeout, array $envelope): void
{
    $envelope = pmssSupportMailEnvelopeNormalize($envelope);
    if ($host === '') {
        throw new RuntimeException('Support relay host is not configured.');
    }

    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, $timeout);
    if (!is_resource($stream)) {
        throw new RuntimeException('Support SMTP connection failed: '.$errstr);
    }

    try {
        stream_set_timeout($stream, $timeout);
        pmssSupportSmtpExpect($stream, [220]);
        $ehloHost = pmssSupportMailHostTokenNormalize((string) (gethostname() ?: 'localhost'), 'localhost');
        foreach ([
            ['EHLO '.$ehloHost, [250]],
            ['MAIL FROM:<'.$envelope['from'].'>', [250]],
            ['RCPT TO:<'.$envelope['to'].'>', [250, 251]],
            ['DATA', [354]],
        ] as $commandSpec) {
            pmssSupportStreamWriteAll($stream, $commandSpec[0]."\r\n", 'support SMTP command');
            pmssSupportSmtpExpect($stream, $commandSpec[1]);
        }

        pmssSupportStreamWriteAll($stream, str_replace("\n.", "\n..", (string) $envelope['data'])."\r\n.\r\n", 'support SMTP message body');
        pmssSupportSmtpExpect($stream, [250]);
        pmssSupportStreamWriteAll($stream, "QUIT\r\n", 'support SMTP quit command');
        pmssSupportSmtpExpect($stream, [221]);
    } finally {
        fclose($stream);
    }
}

/**
 * Read one SMTP response, including multiline replies, and validate it.
 *
 * @param array<int,int> $expectedCodes
 */
function pmssSupportSmtpExpect($stream, array $expectedCodes): void
{
    $line = '';
    do {
        $chunk = fgets($stream);
        if (!is_string($chunk)) {
            $meta = is_resource($stream) ? stream_get_meta_data($stream) : [];
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('Support SMTP server timed out.');
            }
            throw new RuntimeException('Support SMTP server closed the connection unexpectedly.');
        }
        $line = rtrim($chunk, "\r\n");
    } while (strlen($line) >= 4 && $line[3] === '-');

    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Support SMTP error: '.$line);
    }
}
