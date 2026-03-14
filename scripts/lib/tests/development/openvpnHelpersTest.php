<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/openvpn.php';

class OpenvpnHelpersTest extends TestCase
{
    public function testFqdnAddsPmSuffixWhenMissing(): void
    {
        $this->assertEquals('seedbox1.pulsedmedia.com', \pmssOpenvpnFqdnFromHostname('seedbox1'));
    }

    public function testFqdnPreservesExistingPmSuffix(): void
    {
        $this->assertEquals('seedbox1.pulsedmedia.com', \pmssOpenvpnFqdnFromHostname('seedbox1.pulsedmedia.com'));
    }

    public function testFqdnReturnsEmptyForBlankHostname(): void
    {
        $this->assertEquals('', \pmssOpenvpnFqdnFromHostname(" \n\t"));
    }

    public function testSlugReplacesDotsInNormalizedFqdn(): void
    {
        $this->assertEquals('seedbox1-pulsedmedia-com', \pmssOpenvpnSlugFromHostname('seedbox1'));
    }

    public function testArtifactPathsFollowSlugContract(): void
    {
        $this->assertEquals(
            ['/home/openvpn-seedbox1-pulsedmedia-com.ovpn', '/home/openvpn-seedbox1-pulsedmedia-com.crt'],
            \pmssOpenvpnArtifactPathsFromSlug('seedbox1-pulsedmedia-com')
        );
    }

    public function testArtifactPathsStayBlankWithoutSlug(): void
    {
        $this->assertEquals(['', ''], \pmssOpenvpnArtifactPathsFromSlug(''));
    }
}
