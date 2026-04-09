<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ErrorPageTemplateTest extends TestCase
{
    public function testNginxTemplateDefinesAuthenticationErrorPageInHttpAndHttpsServers(): void
    {
        $contents = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-site-default');
        $this->assertEquals(2, substr_count($contents, 'error_page 401 /error-401.html;'));
        $this->assertEquals(2, substr_count($contents, 'location = /error-401.html {'));
    }

    public function testNginxTemplateDefinesForbiddenErrorPageInHttpAndHttpsServers(): void
    {
        $contents = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-site-default');
        $this->assertEquals(2, substr_count($contents, 'error_page 403 /error-403.html;'));
        $this->assertEquals(2, substr_count($contents, 'location = /error-403.html {'));
    }

    public function testAuthenticationErrorPageIncludesHelpfulTextAndHomeLink(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-401.html');
        $this->assertStringContainsString('401 - Authentication Required', $contents);
        $this->assertStringContainsString('Enter your PMSS username', $contents);
        $this->assertStringContainsString('refresh this page to try again', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testAuthenticationErrorPageDefinesImageVariantsAndHomeLink(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-401.html');
        $this->assertEquals(3, substr_count($contents, '.png'));
        $this->assertStringContainsString('/401_images/401-1.png', $contents);
        $this->assertStringContainsString('/401_images/401-2.png', $contents);
        $this->assertStringContainsString('/401_images/401-3.png', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testForbiddenErrorPageIncludesFriendlyTextAndHomeLink(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-403.html');
        $this->assertStringContainsString('403 - Forbidden', $contents);
        $this->assertStringContainsString('The sage guards this path.', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testForbiddenErrorPageDefinesTwentyTwoImageVariants(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-403.html');
        $this->assertEquals(22, substr_count($contents, '.png'));
        $this->assertStringContainsString('/404_images/404-1.png', $contents);
        $this->assertStringContainsString('/404_images/404-22.png', $contents);
    }

    public function testNotFoundErrorPageDefinesTwentyTwoImageVariantsAndHomeLink(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-404.html');
        $this->assertEquals(22, substr_count($contents, '.png'));
        $this->assertStringContainsString('/404_images/404-1.png', $contents);
        $this->assertStringContainsString('/404_images/404-22.png', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testBadGatewayErrorPageDefinesThirteenImageVariantsAndHomeLink(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-502.html');
        $this->assertEquals(13, substr_count($contents, '.png'));
        $this->assertStringContainsString('/502_images/502-1.png', $contents);
        $this->assertStringContainsString('/502_images/502-13.png', $contents);
        $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
    }

    public function testBadGatewayErrorPageRestoresActionableRecoveryGuidance(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-502.html');
        $this->assertStringContainsString('Your disk quota is full', $contents);
        $this->assertStringContainsString('connect with SFTP and delete files', $contents);
        $this->assertStringContainsString('account is suspended', $contents);
        $this->assertStringContainsString('server-wide storage pressure', $contents);
    }

    public function testSuspendedErrorPageDoesNotReferenceABrokenImage(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-suspended.html');
        $this->assertStringNotContainsString('<img', $contents);
        $this->assertStringContainsString('Account Suspended', $contents);
        $this->assertStringContainsString('https://pulsedmedia.com/contact/', $contents);
    }

    public function testSuspendedErrorPageKeepsSupportCallToActionReadable(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-suspended.html');
        $this->assertStringContainsString('class="cta"', $contents);
        $this->assertStringContainsString('.cta:visited', $contents);
        $this->assertStringContainsString('color:#fff;', $contents);
    }

    public function testUserNginxTemplateUsesPerUser502FallbackPage(): void
    {
        $contents = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        $this->assertEquals(3, substr_count($contents, 'error_page 502 /error-502-##username.html;'));
        $this->assertStringContainsString('location = /error-502-##username.html {', $contents);
        $this->assertStringContainsString('try_files $uri /error-502.html;', $contents);
    }

    public function testPrivateSubdomainTemplateUsesPerUser502FallbackPage(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';
        $contents = \pmssNginxUserSubdomainTemplates()['private'];
        $this->assertEquals(4, substr_count($contents, 'error_page 502 /error-502-##user##.html;'));
        $this->assertStringContainsString('location = /error-502-##user##.html {', $contents);
        $this->assertStringContainsString('try_files $uri /error-502.html;', $contents);
    }

}
