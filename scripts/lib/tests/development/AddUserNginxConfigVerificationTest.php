<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/add/userConfigApply.php';

/**
 * Covers addUser nginx config verification helpers.
 */
class AddUserNginxConfigVerificationTest extends TestCase
{
    public function testExpectedNginxConfigPathMatchesCanonicalLocation(): void
    {
        $this->assertEquals('/etc/nginx/users/alice', \pmssAddUserExpectedNginxConfigPath('alice'));
    }

    public function testProvisioningFailsLoudlyWhenConfigIsMissing(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');

        $this->assertStringContainsString('nginx config missing after regeneration; aborting provisioning', $source);
        $this->assertStringContainsString("finalizeProvision('FAIL', 'nginx_config_missing', 1);", $source);
    }
}
