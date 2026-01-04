<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProftpdTemplateTest extends TestCase
{
    public function testTemplateRemovesDeprecatedMultilineRfc2228Directive(): void
    {
        $template = $this->loadTemplate();
        $this->assertTrue(strpos($template, 'MultilineRFC2228') === false, 'Deprecated MultilineRFC2228 directive should not be present');
    }

    public function testTemplateForcesDefaultAddressToAvoidHostnameResolution(): void
    {
        $template = $this->loadTemplate();
        $this->assertTrue(strpos($template, "DefaultAddress                  0.0.0.0\n") !== false, 'DefaultAddress 0.0.0.0 should be present to avoid hostname/IP resolution failures');
    }

    public function testTemplateStillContainsServerNamePlaceholder(): void
    {
        $template = $this->loadTemplate();
        $this->assertTrue(strpos($template, 'ServerName') !== false, 'ServerName directive should remain present');
        $this->assertTrue(strpos($template, '%SERVERNAME%') !== false, 'ServerName placeholder must remain present for template substitution');
    }

    private function loadTemplate(): string
    {
        $root = dirname(__DIR__, 4);
        $path = $root.'/etc/seedbox/config/template.proftpd';
        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            throw new \AssertionError('Unable to load ProFTPD template at '.$path);
        }
        return $data;
    }
}

