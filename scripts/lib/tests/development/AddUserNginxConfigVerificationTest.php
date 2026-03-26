<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/add/userConfigApply.php';
require_once __DIR__.'/../../user/add/artifactVerification.php';

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

    public function testRequiredArtifactPathsCoverProvisioningOutputs(): void
    {
        $expected = [
            'nginx_config' => '/etc/nginx/users/alice',
            'rtorrent_config' => '/home/alice/.rtorrent.rc',
            'lighttpd_config' => '/home/alice/.lighttpd.conf',
            'quota_snapshot' => '/home/alice/.quota',
        ];

        $this->assertEquals($expected, \pmssAddUserRequiredArtifactPaths('alice', '/home/alice'));
    }

    public function testAddUserVerifiesArtifactsBeforeReportingSuccess(): void
    {
        $source = $this->pmssReadRepoFile('scripts/addUser.php');

        $verifyPos = strpos($source, "pmssAddUserVerifyArtifactsOrFail(\$user['name'], \$homePath);");
        $successPos = strpos($source, "finalizeProvision('SUCCESS', 'completed', 0);");

        $this->assertTrue($verifyPos !== false, 'addUser.php must verify required artifacts');
        $this->assertTrue($successPos !== false, 'addUser.php must still report success when complete');
        $this->assertTrue($verifyPos < $successPos, 'artifact verification must happen before SUCCESS is reported');
    }
}
