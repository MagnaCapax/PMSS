<?php
namespace PMSS\Tests;

class NginxDefaultServerTest extends TestCase
{
    public function testDefaultSiteTemplateDefinesHttpDefaultServer(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertMatches('/\\blisten\\s+80\\s+default_server\\s*;/', $contents);
    }

    public function testDefaultSiteTemplateDefinesHttpsDefaultServer(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertMatches('/\\blisten\\s+443\\s+ssl\\s+default_server\\s*;/', $contents);
    }

    public function testDefaultSiteTemplateDoesNotUseDeprecatedSslOnDirective(): void
    {
        $path = 'etc/seedbox/config/template.nginx-site-default';
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        $this->assertTrue(strpos($contents, 'ssl on;') === false, 'Deprecated \"ssl on;\" directive should be removed');
    }
}
