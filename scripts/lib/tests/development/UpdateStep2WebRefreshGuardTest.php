<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Characterize the abnormal-exit nginx refresh guard in update-step2.
 */
class UpdateStep2WebRefreshGuardTest extends TestCase
{
    public function testUpdateStep2RegistersShutdownGuardForWebRefresh(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'function pmssUpdateStep2RegisterShutdownGuard(): void',
            'pmssUpdateStep2RegisterShutdownGuard();',
            "\$GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_PENDING'] = true;",
            "\$GLOBALS['PMSS_UPDATE_STEP2_WEB_REFRESH_PENDING'] = false;",
            "/scripts/util/createNginxConfig.php --restart",
            "'PMSS_UPDATE_STEP2_COMPLETED'",
        ]);
        $this->pmssAssertRepoFileSubstringCountAtLeast(
            'scripts/util/update-step2.php',
            'register_shutdown_function(',
            3,
            'The unified phase-2 rescue guard must coexist with lock and cron cleanup guards'
        );
    }

    public function testWebStackRegeneratesAllNginxConfigsFromStagedTemplate(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
            'required' => [
                "runStep('Regenerating nginx configs from staged templates', '/scripts/util/createNginxConfig.php')",
                "throw new RuntimeException('nginx_config_regeneration_failed');",
            ],
            'ordered' => [[
                'needles' => [
                    "function pmssConfigureWebStack(): void",
                    'Regenerating nginx configs from staged templates',
                    'Updating all user environments',
                    'Post-update nginx configuration refresh',
                ],
            ]],
        ]);
    }
}
