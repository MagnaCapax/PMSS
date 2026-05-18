<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProftpdTemplateTest extends TestCase
{
    public function testTemplateRemovesDeprecatedMultilineRfc2228Directive(): void
    {
        $template = $this->loadTemplate();
        $this->pmssAssertStringNotContainsString('MultilineRFC2228', $template, 'Deprecated MultilineRFC2228 directive should not be present');
    }

    public function testTemplateForcesDefaultAddressToAvoidHostnameResolution(): void
    {
        $template = $this->loadTemplate();
        $this->assertStringContainsString("DefaultAddress                  0.0.0.0\n", $template, 'DefaultAddress 0.0.0.0 should be present to avoid hostname/IP resolution failures');
    }

    public function testTemplateStillContainsServerNamePlaceholder(): void
    {
        $template = $this->loadTemplate();
        $this->assertStringContainsString('ServerName', $template, 'ServerName directive should remain present');
        $this->assertStringContainsString('%SERVERNAME%', $template, 'ServerName placeholder must remain present for template substitution');
    }

    private function loadTemplate(): string
    {
        return $this->pmssReadRepoFile('etc/seedbox/config/template.proftpd');
    }
}
