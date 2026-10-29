<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProftpdTemplateTest extends TestCase
{
    public function testTemplateRemovesDeprecatedMultilineRfc2228Directive(): void
    {
        $this->pmssAssertRepoFileNotContainsString('etc/seedbox/config/template.proftpd', 'MultilineRFC2228', 'Deprecated MultilineRFC2228 directive should not be present');
    }

    public function testTemplateForcesDefaultAddressToAvoidHostnameResolution(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.proftpd', "DefaultAddress                  0.0.0.0\n", 'DefaultAddress 0.0.0.0 should be present to avoid hostname/IP resolution failures');
    }

    public function testTemplateStillContainsServerNamePlaceholder(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.proftpd', [
            'ServerName' => 'ServerName directive should remain present',
            '%SERVERNAME%' => 'ServerName placeholder must remain present for template substitution',
        ]);
    }

}
