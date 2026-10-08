<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ErrorPageTemplateTest extends TestCase
{
    public function testNginxTemplateKeepsErrorAndTestfileGuards(): void
    {
        $contents = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-site-default');
        foreach ([
            'error_page 401 /error-401.html;' => 2,
            'location = /error-401.html {' => 2,
            'error_page 403 /error-403.html;' => 2,
            'location = /error-403.html {' => 2,
            'limit_conn_zone $binary_remote_addr zone=testfile:10m;' => 1,
            'location = /testfile {' => 2,
            'limit_conn testfile 16;' => 2,
            'limit_conn_status 429;' => 2,
        ] as $needle => $count) {
            $this->assertEquals($count, substr_count($contents, $needle), $needle);
        }
        $this->assertEquals(2, preg_match_all('/location \/ \{[^}]*try_files \$uri \$uri\/ =404;[^}]*\}/', $contents));
        $this->assertEquals(0, substr_count($contents, 'try_files $uri $uri/ /index.html;'));
    }

    public function testErrorPagesDefineImageVariantsAndHomeLinks(): void
    {
        foreach ([
            ['var/www/error-401.html', '/401_images/401-', 5, true],
            ['var/www/error-403.html', '/404_images/404-', 32, false],
            ['var/www/error-404.html', '/404_images/404-', 32, true],
            ['var/www/error-502.html', '/502_images/502-', 13, true],
        ] as [$path, $prefix, $count, $hasHomeLink]) {
            $contents = $this->assertErrorPageImagePool($path, $prefix, $count);
            $this->assertStringContainsString('data-error-image-ext="jpg"', $contents, $path);
            $this->assertImageFilesExist(ltrim($prefix, '/'), $count);
            if ($hasHomeLink) {
                $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
            }
        }
    }

    public function testLandingPageKeepsLinksAndImagePool(): void
    {
        $contents = $this->assertErrorPageImagePool('var/www/index.html', '/landing_images/landing-', 5);
        $this->assertStringContainsAllStrings([
            '<!DOCTYPE html>', '<html lang="en">', 'Pulsed Media Seedbox Server',
            '/user-YOURUSERNAME/', 'data-error-image-ext="jpg"',
            'https://pulsedmedia.com/clients/clientarea.php',
            'https://wiki.pulsedmedia.com/wiki/Getting_Started_with_the_Seedbox_Dashboard',
            'https://pulsedmedia.com/clients/knowledgebase.php?action=displayarticle&amp;id=108',
            'https://pulsedmedia.com/clients/submitticket.php',
            "e.textContent='https://'+location.host+'/user-YOURUSERNAME/'",
        ], $contents);
        $this->assertEquals(0, substr_count($contents, 'innerHTML'));
        $this->assertImageFilesExist('landing_images/landing-', 5);
    }

    public function testErrorPageScriptKeepsPngDefaultAndImageBehavior(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-page.js');
        $this->assertStringContainsAllStrings([
            "getAttribute('data-error-image-ext') || 'png'",
            "['png', 'jpg', 'webp'].indexOf(extension)",
            "extension = 'png'", "'.' + extension",
            'Math.floor(Math.random() * count) + 1',
            "imageElement.style.display = 'none'",
            "link.href = '/css/error-styles.css'",
        ], $contents);
    }

    public function testSuspendedPageInlineBackgroundMatchesBody(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-suspended.html');
        $this->assertEquals(1, preg_match('/<style>(.*?)<\/style>/s', $contents, $style));
        $this->assertEquals(1, preg_match('/\bhtml\s*\{[^}]*background:\s*(#[0-9a-fA-F]{6})\s*;/', $style[1], $html));
        $this->assertEquals(1, preg_match('/\bbody\s*\{[^}]*background:\s*(#[0-9a-fA-F]{6})\s*;/', $style[1], $body));
        $this->assertEquals($body[1], $html[1]);
    }

    public function testImageDirectoriesStayUnignored(): void
    {
        $lines = explode("\n", $this->pmssReadRepoFile('.gitignore'));
        foreach (['401_images', 'landing_images', 'suspended_images'] as $directory) {
            foreach (['', '/*'] as $suffix) {
                $line = '!/var/www/'.$directory.$suffix;
                $this->assertTrue(in_array($line, $lines, true), $line.' is missing from .gitignore');
            }
        }
        foreach (['404_images', '502_images'] as $directory) {
            $this->assertTrue(in_array('!/var/www/'.$directory, $lines, true));
            $this->assertTrue(in_array('!/var/www/'.$directory.'/*.jpg', $lines, true));
        }
    }

    public function testErrorPageSourceContractsStayReadable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'var/www/error-401.html' => ['required' => [
                '401 - Authentication Required',
                'Enter your PMSS username',
                'refresh this page to try again',
                'Use Generate new password on your seedbox service page',
                '<a href="/">Return to the main page.</a>',
            ]],
            'var/www/error-403.html' => ['required' => [
                '403 - Forbidden',
                'The sage guards this path.',
                'It is at /user-YOURUSERNAME/ on this server.',
                '<a href="/">Return to the main page.</a>',
            ]],
            'var/www/error-404.html' => ['required' => ['It is at /user-YOURUSERNAME/ on this server.']],
            'var/www/error-502.html' => ['required' => ['Your disk quota is full', 'stop the torrent client before deleting files', 'account is suspended', 'server-wide storage pressure', 'Use Restart account on your seedbox service page']],
            'etc/seedbox/config/template.lighttpd' => ['required' => ['server.errorfile-prefix    = "/home/##username/www/error-"']],
            'etc/skel/www/error-503.html' => ['required' => [
                '503 - Service Unavailable',
                'class="error-message"',
                'The sage is waiting for this application to answer.',
                'pmss503check=',
                'window.fetch(retryUrl(), {',
                'window.location.reload();',
                '<a href="/">Return to the main page.</a>',
            ]],
            'var/www/error-suspended.html' => [
                'required' => ['Account Suspended', '/suspended_images/suspended-', "e.onerror=function(){e.style.display='none';}", 'To restore access, check your invoices', 'https://pulsedmedia.com/contact/', 'class="cta"', '.cta:visited', 'color:#fff;'],
            ],
        ]);
        $this->assertImageFilesExist('suspended_images/suspended-', 5);
    }

    public function testNginxTemplatesUsePerUser502FallbackPages(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';

        foreach ([
            'user template' => [$this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'), '##username', 3],
        ] as $label => [$contents, $token, $count]) {
            $this->assertEquals($count, substr_count($contents, 'error_page 502 /error-502-'.$token.'.html;'), $label);
            $this->assertStringContainsAllStrings(['location = /error-502-'.$token.'.html {', 'try_files $uri /error-502.html;'], $contents, $label.': ');
        }
        $suspended = \pmssNginxUserSubdomainTemplates()['publicSuspended'];
        $this->assertEquals(2, substr_count($suspended, 'location ^~ /suspended_images/'));
        $this->assertTrue(strpos($suspended, 'location ^~ /suspended_images/') < strpos($suspended, 'location / {'));
    }

    private function assertImageFilesExist(string $prefix, int $count): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $path = 'var/www/'.$prefix.$number.'.jpg';
            $this->assertTrue(is_file($this->pmssRepoPath($path)), $path.' is missing');
        }
    }

    private function assertErrorPageImagePool(string $path, string $prefix, int $count): string
    {
        $contents = $this->pmssReadRepoFile($path);
        $this->assertStringContainsAllStrings(['data-error-image-prefix="'.$prefix.'"', 'data-error-image-count="'.$count.'"', '<script src="/error-page.js"></script>'], $contents);
        return $contents;
    }
}
