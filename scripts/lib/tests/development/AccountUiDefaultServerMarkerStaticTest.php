<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * ADR 0150: no new public listener on a host that has not turned the account UIs on. Each account-UI default server
 * written by the nginx setup (ports 844, 845) sits behind the host marker check. Port 846 is retired.
 */
final class AccountUiDefaultServerMarkerStaticTest extends TestCase
{
    private const MARKER = "is_file('/etc/seedbox/config/pikkumiesEternals.enabled')";

    public function testEveryAccountUiDefaultServerNeedsTheHostMarker(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/nginxConfig/setupAccountUi.php', [
            "'pmss-pikkumies-default.conf' => [844, '/opt/pikkumies-agentboard/agentboard', 'pmssPikkumiesUiDefaultNginxBlock'",
            "'pmss-pikkumies-hermes-default.conf' => [845, null, 'pmssPikkumiesHermesDefaultNginxBlock'",
            "pmssCreateNginxConfigSetupRemoveDefault(\$subdomainConfigDir.'/pmss-hermes-default.conf')",
        ], ['pmssHermesUiDefaultNginxBlock']);
        // Both table entries must pass the same marker, binary and port guard before publication.
        $guard = strpos($source, "if (\$subdomainEnabled && \$nginxSslBlock !== ''");
        $this->assertTrue($guard !== false, 'no guarding if before default publication');
        $condition = substr($source, $guard, strpos($source, ') {', $guard) - $guard);
        $this->assertStringContainsAllStrings([self::MARKER,
            '($binary === null || is_file($binary))', 'pmssPikkumiesUiPortAvailable($port)'], $condition);
        $this->assertOrderedStrings(['foreach ([', $condition, 'pmssWriteManagedFile($path,'], $source);
    }
}
