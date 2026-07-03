<?php
/**
 * Logrotate policy convergence for PMSS-managed host logs.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../runtime/commands.php';

/** Install and verify one PMSS-managed logrotate policy. */
function pmssLogrotatePolicyInstall(string $template, string $target, string $label): void
{
    if (!is_file($template)) {
        pmssLogStatus('SKIP', 'Installing logrotate policy for '.$label.' (template missing: '.$template.')');
        return;
    }

    $installRc = runStep(
        'Installing logrotate policy for '.$label,
        sprintf('install -m 0644 -T %s %s', escapeshellarg($template), escapeshellarg($target))
    );
    $verifyRc = runStep(
        'Verifying logrotate policy for '.$label.' matches template',
        sprintf('cmp -s %s %s', escapeshellarg($template), escapeshellarg($target))
    );

    if ($installRc !== 0 || $verifyRc !== 0) {
        $rc = $installRc !== 0 ? $installRc : $verifyRc;
        throw new \RuntimeException('logrotate policy install or verify failed for '.$label.' rc='.$rc);
    }
}

/** Apply all PMSS-owned logrotate policies after core permissions settle. */
function pmssLogrotatePoliciesInstall(): void
{
    $policies = [
        ['template' => '/etc/seedbox/config/template.logrotate.pmss', 'target' => '/etc/logrotate.d/pmss-update', 'label' => 'PMSS update logs'],
        ['template' => '/etc/seedbox/config/template.logrotate.rsyslog', 'target' => '/etc/logrotate.d/rsyslog', 'label' => 'rsyslog OS logs'],
    ];

    foreach ($policies as $policy) {
        pmssLogrotatePolicyInstall($policy['template'], $policy['target'], $policy['label']);
    }
}
