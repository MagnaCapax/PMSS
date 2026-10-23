<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2NginxShutdownGuardTest extends TestCase
{
    /** Freeze command bytes, event payloads, logs, dry runs and exception boundaries. */
    public function testShutdownTracesMatchBeforeRefactor(): void
    {
        $permissionLog = ['log', '[WARN] update-step2 exited before final permission refresh; attempting permission rescue run (reason: early_exit)'];
        $permissionStart = ['json', ['event' => 'permission_refresh_rescue', 'status' => 'start', 'reason' => 'early_exit']];
        $permissionStep = ['step', 'Restoring system permissions (shutdown)', '/scripts/util/setupPermissions.php'];
        $permissions = [$permissionLog, $permissionStart, $permissionStep,
            ['json', ['event' => 'permission_refresh_rescue', 'status' => 'ok', 'rc' => 0]]];
        $reloadLog = ['log', '[WARN] update-step2 exited before final nginx refresh; attempting rescue run (reason: early_exit)'];
        $reloadStart = ['json', ['event' => 'post_update_web_refresh_rescue', 'status' => 'start', 'reason' => 'early_exit']];
        $reloadCommand = ['passthru', 'close-lock-fds /scripts/util/createNginxConfig.php --reload'];
        $reload = [$reloadLog, $reloadStart, $reloadCommand,
            ['json', ['event' => 'post_update_web_refresh_rescue', 'status' => 'ok', 'rc' => 0]],
            ['log', '[WARN] Rescue nginx refresh completed with rc=0']];
        $fallback = [
            ['log', '[WARN] update-step2 exited with nginx still pending final refresh; attempting direct start (reason: create_nginx_config_missing)'],
            ['json', ['event' => 'post_update_nginx_start_fallback', 'status' => 'start', 'reason' => 'create_nginx_config_missing']],
            ['passthru', 'close-lock-fds systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null'],
            ['json', ['event' => 'post_update_nginx_start_fallback', 'status' => 'ok', 'rc' => 0]],
            ['log', '[WARN] Direct nginx start fallback completed with rc=0'],
        ];
        $missing = [['log', '[WARN] update-step2 exited before final nginx refresh; createNginxConfig.php missing']];
        $failedReload = [$reloadLog, $reloadStart, $reloadCommand,
            ['json', ['event' => 'post_update_web_refresh_rescue', 'status' => 'error', 'rc' => 9]],
            ['log', '[WARN] Rescue nginx refresh completed with rc=9']];
        $failedFallback = $fallback;
        $failedFallback[0][1] = '[WARN] update-step2 exited with nginx still pending final refresh; attempting direct start (reason: web_refresh_rescue_failed)';
        $failedFallback[1][1]['reason'] = 'web_refresh_rescue_failed';
        $failedFallback[3][1] = ['event' => 'post_update_nginx_start_fallback', 'status' => 'error', 'rc' => 7];
        $failedFallback[4][1] = '[WARN] Direct nginx start fallback completed with rc=7';
        $failedPermissions = [$permissionLog, $permissionStart, $permissionStep,
            ['json', ['event' => 'permission_refresh_rescue', 'status' => 'error', 'rc' => 4]]];
        // A captured shutdown error is read independently for both recovery stages.
        $originalError = array_merge($reload, $permissions);
        $originalError[0][1] = '[WARN] update-step2 exited before final nginx refresh; attempting rescue run (reason: original failure)';
        $originalError[1][1]['reason'] = 'original failure';
        $originalError[5][1] = '[WARN] update-step2 exited before final permission refresh; attempting permission rescue run (reason: original failure)';
        $originalError[6][1]['reason'] = 'original failure';
        $expected = [
            'completed' => [], 'dry_idle' => [], 'permissions' => $permissions,
            'permissions_missing' => [['log', '[WARN] update-step2 exited before final permission refresh; setupPermissions.php missing']],
            'reload' => $originalError,
            'reload_failed' => array_merge($failedReload, $failedFallback, $failedPermissions),
            'nginx_missing' => array_merge($missing, $fallback, $permissions),
            'dry_pending' => $reload, 'dry_missing' => array_merge($missing, $fallback),
            'empty_error' => $permissions, 'nonstring_error' => $permissions,
            'throw_web' => [$reloadLog, $reloadStart, $reloadCommand, ['throw', 'RuntimeException', 'web failure']],
            'throw_permissions' => [$permissionLog, $permissionStart, $permissionStep, ['throw', 'RuntimeException', 'permission failure']],
        ];
        $result = $this->pmssRunRepoPhpScript('scripts/lib/tests/fixtures/updateStep2ShutdownTrace.php');
        $this->assertSame($expected, json_decode($result, true));
    }

    public function testUpdateStep2ShutdownGuardKeepsDirectNginxStartFallback(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/runtime/shutdown.php', [
            'function pmssUpdateStep2StartNginxShutdownFallback(string $reason): void',
            'function pmssUpdateStep2RunRescueCommand(',
            "'systemctl start nginx 2>/dev/null || /etc/init.d/nginx start 2>/dev/null'",
            'passthru(pmssLockChildClosePrefix().$command, $rc)',
            "pmssUpdateStep2StartNginxShutdownFallback('create_nginx_config_missing');",
            "pmssUpdateStep2StartNginxShutdownFallback('web_refresh_rescue_failed');",
        ]);
    }
}
