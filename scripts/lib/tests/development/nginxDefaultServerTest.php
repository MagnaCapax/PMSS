<?php
namespace PMSS\Tests;

class NginxDefaultServerTest extends TestCase
{
    public function testRegexExhaustionPreservesTemplateBytes(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        $limit = ini_get('pcre.backtrack_limit');
        try {
            // Force the actual PCRE failure without writing a service config.
            ini_set('pcre.backtrack_limit', '0');
            foreach ([
                "server {\n    listen 80;\n}\n",
                "server {\n    listen 443 ssl;\n}\n",
                "listen 80;\nlisten 443 ssl;\n",
                "listen 80 default_server;\n",
                "listen 443 ssl default_server;\n",
            ] as $input) {
                $this->assertSame($input, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input));
                $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
            }
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    public function testNormalRenderingPreservesExactConfigContract(): void
    {
        require_once dirname(__DIR__, 2).'/nginxConfig/setup.php';

        foreach ([
            '' => '',
            "# no listeners\n" => "# no listeners\n",
            "listen 8080;\n" => "listen 8080;\n",
            "listen 443;\n" => "listen 443;\n",
            "listen 80 default_server;\n" => "listen 80 default_server;\n",
            "listen 443 ssl default_server;\n" => "listen 443 ssl default_server;\n",
            "  listen 80; # http\n  listen 443 ssl; # https\n"
                => "  listen 80 default_server; # http\n  listen 443 ssl default_server; # https\n",
        ] as $input => $expected) {
            $this->assertSame($expected, \pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer($input));
        }
    }

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
