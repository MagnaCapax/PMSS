<?php
namespace PMSS\Tests;

class ErrorPageTemplateTest extends TestCase
{
    public function testNginxTemplateDefinesAuthenticationErrorPageInHttpAndHttpsServers(): void
    {
        $contents = $this->readFile('etc/seedbox/config/template.nginx-site-default');
        $this->assertEquals(2, substr_count($contents, 'error_page 401 /error-401.html;'));
        $this->assertEquals(2, substr_count($contents, 'location = /error-401.html {'));
    }

    public function testNginxTemplateDefinesForbiddenErrorPageInHttpAndHttpsServers(): void
    {
        $contents = $this->readFile('etc/seedbox/config/template.nginx-site-default');
        $this->assertEquals(2, substr_count($contents, 'error_page 403 /error-403.html;'));
        $this->assertEquals(2, substr_count($contents, 'location = /error-403.html {'));
    }

    public function testAuthenticationErrorPageIncludesHelpfulTextAndHomeLink(): void
    {
        $contents = $this->readFile('var/www/error-401.html');
        $this->assertStringContainsString('401 - Authentication Required', $contents);
        $this->assertStringContainsString('Enter your PMSS username', $contents);
        $this->assertStringContainsString('refresh this page to try again', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testForbiddenErrorPageIncludesFriendlyTextAndHomeLink(): void
    {
        $contents = $this->readFile('var/www/error-403.html');
        $this->assertStringContainsString('403 - Forbidden', $contents);
        $this->assertStringContainsString('The sage guards this path.', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testForbiddenErrorPageDefinesThreeImageVariants(): void
    {
        $contents = $this->readFile('var/www/error-403.html');
        $this->assertEquals(3, substr_count($contents, '.png'));
        $this->assertStringContainsString('/404_images/404-1.png', $contents);
        $this->assertStringContainsString('/404_images/404-2.png', $contents);
        $this->assertStringContainsString('/404_images/404-3.png', $contents);
    }

    public function testNotFoundErrorPageDefinesThreeImageVariantsAndHomeLink(): void
    {
        $contents = $this->readFile('var/www/error-404.html');
        $this->assertEquals(3, substr_count($contents, '.png'));
        $this->assertStringContainsString('/404_images/404-1.png', $contents);
        $this->assertStringContainsString('/404_images/404-2.png', $contents);
        $this->assertStringContainsString('/404_images/404-3.png', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testBadGatewayErrorPageDefinesThreeImageVariantsAndHomeLink(): void
    {
        $contents = $this->readFile('var/www/error-502.html');
        $this->assertEquals(3, substr_count($contents, '.png'));
        $this->assertStringContainsString('/502_images/502-1.png', $contents);
        $this->assertStringContainsString('/502_images/502-2.png', $contents);
        $this->assertStringContainsString('/502_images/502-3.png', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $this->assertTrue(is_string($contents) && $contents !== '', 'Failed to read '.$path);
        return (string)$contents;
    }
}
