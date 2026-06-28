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
            'function pmssUpdateStep2RegisterWebRefreshShutdownGuard(): void',
            'pmssUpdateStep2RegisterWebRefreshShutdownGuard();',
            'pmssUpdateStep2MarkWebRefreshRequired();',
            'pmssUpdateStep2MarkWebRefreshCompleted();',
            "/scripts/util/createNginxConfig.php --restart",
            "'PMSS_UPDATE_STEP2_COMPLETED'",
        ]);
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
