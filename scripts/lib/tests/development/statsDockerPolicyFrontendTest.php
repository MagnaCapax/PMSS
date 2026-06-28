<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsDockerPolicyFrontendTest extends TestCase
{
    public function testStatsPageKeepsDockerPolicyReadOnlyInsideCustomerTree(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/stats.php',
            ['Docker policy:', 'Docker policy changes are handled by platform tooling.', 'Platform managed'],
            [
                'function pmssInfoDockerPolicy'.'StoreState()',
                'pmssInfoSetDocker'.'Enabled',
                '/scripts/lib/user/userConfigStore.php',
                '/scripts/userDocker.php',
                'UserConfigStore',
                'docker_toggle_state',
            ]
        );
    }

    public function testStatsRenderShowsReadOnlyDockerPolicy(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php');

        $this->assertStringContainsAndOmitsStrings(['Docker policy:', 'Platform managed'], ['docker_toggle_state'], $html);
    }
}
