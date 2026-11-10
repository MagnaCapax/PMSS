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

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/stream.php';

require_once __DIR__.'/envelope.php';
require_once __DIR__.'/sendmail.php';
require_once __DIR__.'/smtp.php';

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
