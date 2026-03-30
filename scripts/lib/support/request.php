<?php
/**
 * Support request orchestration helpers.
 *
 * Coordinates configuration, diagnostics, snapshot persistence, and delivery so
 * the CLI entrypoint can stay small and easy to review.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/mail.php';

/**
 * Submit a support request and return the saved snapshot path.
 *
 * @return array<string,mixed>
 */
function pmssSupportRequestSubmit(string $message, ?callable $runner = null, ?callable $transport = null): array
{
    $config = pmssSupportConfigRead();
    $diagnostics = pmssSupportDiagnosticsBuild($message, $runner);
    $snapshotPath = pmssSupportSnapshotWrite($diagnostics, $config);
    $envelope = pmssSupportMailEnvelopeBuild($diagnostics, $config, $snapshotPath);
    pmssSupportMailSend($config, $envelope, $transport);

    return [
        'snapshotPath' => $snapshotPath,
        'diagnostics' => $diagnostics,
    ];
}
