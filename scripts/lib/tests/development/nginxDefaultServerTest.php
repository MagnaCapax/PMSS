<?php
namespace PMSS\Tests;

class NginxDefaultServerTest extends TestCase
{
    public function testConfigSetupEnforcesHttpDefaultServerEvenOnStaleTemplates(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $input = "server {\n    listen 80;\n}\n";
        $output = \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input);
        $this->assertMatches('/\\blisten\\s+80\\s+default_server\\s*;/', $output);
        $this->assertEquals($output, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($output));
    }

    public function testConfigSetupEnforcesHttpsDefaultServerEvenOnStaleTemplates(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $input = "server {\n    listen 443 ssl;\n}\n";
        $output = \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input);
        $this->assertMatches('/\\blisten\\s+443\\s+ssl\\s+default_server\\s*;/', $output);
        $this->assertEquals($output, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($output));
    }

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
