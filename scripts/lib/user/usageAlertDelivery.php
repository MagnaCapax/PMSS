<?php
/**
 * Per-user usage-alert mail delivery orchestration.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/usageAlerts.php';
require_once __DIR__.'/userConfigStore.php';
require_once dirname(__DIR__).'/support/config.php';
require_once dirname(__DIR__).'/support/mail.php';

/** Build a plain-text customer notification without exposing internal paths. */
function pmssUsageAlertEnvelopeBuild(string $user, string $recipient, array $conditions, string $hostname): array
{
    $senderHost = pmssSupportMailHostTokenNormalize($hostname, 'pmss.local');
    $from = 'usage-alerts@'.$senderHost;
    $subject = '[PMSS Usage Alert] account '.$user;
    $lines = [
        'One or more usage alerts crossed their configured threshold:',
        '',
    ];
    foreach ($conditions as $message) $lines[] = '- '.trim((string) $message);
    $lines[] = '';
    $lines[] = 'Open the PMSS customer panel for current details.';

    return [
        'from' => $from,
        'to' => $recipient,
        'data' => implode("\r\n", [
            'From: '.$from,
            'To: '.$recipient,
            'Subject: '.$subject,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            implode("\r\n", $lines),
            '',
        ]),
    ];
}

/** Notify one user, returning a stable status for cron observability. */
function pmssUsageAlertsNotifyUser(string $user, ?UserConfigStore $store = null, ?callable $transport = null, ?array $mailConfig = null): string
{
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    $runtimeRoot = pmssResolvePathFromEnv('PMSS_SEEDBOX_RUNTIME_DIR', '/etc/seedbox/runtime');
    $stateDir = pmssResolvePathFromEnv('PMSS_USAGE_ALERTS_STATE_DIR', pmssStateDir().'/usageAlerts');

    if (!pmssUsageAlertsOptInEnabled($user, $homeRoot)) {
        pmssUsageAlertsStateClear($user, [], $stateDir);
        return 'disabled';
    }

    $store = $store ?: new UserConfigStore();
    $recipient = $store->readNotificationEmail($user);
    if ($recipient === null) {
        pmssUsageAlertsStateClear($user, [], $stateDir);
        return 'recipient_missing';
    }

    $active = pmssUsageAlertsConditionsRead($user, $homeRoot, $runtimeRoot);
    pmssUsageAlertsStateClear($user, $active, $stateDir);
    $new = pmssUsageAlertsNewConditions($user, $active, $stateDir);
    if ($new === []) return $active === [] ? 'clear' : 'unchanged';

    $config = $mailConfig ?? pmssSupportConfigRead();
    // Direct-MX fallback must resolve the customer's domain, not support mail.
    $config['targetEmail'] = $recipient;
    $hostname = (string) (gethostname() ?: 'pmss.local');
    pmssSupportMailSend($config, pmssUsageAlertEnvelopeBuild($user, $recipient, $new, $hostname), $transport);
    if (!pmssUsageAlertsStateRecord($user, $new, $stateDir)) {
        throw new RuntimeException('Usage alert delivered but notification state could not be recorded.');
    }
    return 'sent';
}
