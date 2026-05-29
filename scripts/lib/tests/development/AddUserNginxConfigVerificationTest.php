<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Covers addUser nginx config verification helpers.
 */
class AddUserNginxConfigVerificationTest extends TestCase
{
    public function testCanonicalNginxConfigPathStaysInProvisioningGuards(): void
    {
        $userConfigApply = $this->pmssReadRepoFile('scripts/lib/user/add/userConfigApply.php');
        $artifactVerification = $this->pmssReadRepoFile('scripts/lib/user/add/artifactVerification.php');

        $this->assertStringContainsString("'/etc/nginx/users/'.\$user['name']", $userConfigApply);
        $this->assertStringContainsString("'/etc/nginx/users/'.\$userName", $artifactVerification);
    }

    public function testProvisioningFailsLoudlyWhenConfigIsMissing(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/add/userConfigApply.php', ['nginx config missing after regeneration; aborting provisioning', "pmssAddUserFatalExit('FAIL', 'nginx config missing after regeneration; aborting provisioning', 'nginx_config_missing');"]);
    }

    public function testRequiredArtifactsStayAlongsideSuccessGuard(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/artifactVerification.php');

        $this->assertStringContainsAllStrings(array(
            "'nginx_config' => '/etc/nginx/users/'.\$userName",
            "'rtorrent_config' => \$homePath.'/.rtorrent.rc'",
            "'lighttpd_config' => \$homePath.'/.lighttpd.conf'",
            "'lighttpd_htpasswd' => \$homePath.'/.lighttpd/.htpasswd'",
            "'quota_snapshot' => \$homePath.'/.quota'",
        ), $source);
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
