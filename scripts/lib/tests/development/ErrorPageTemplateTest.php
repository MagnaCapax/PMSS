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
    }

    public function testErrorPagesDefineImageVariantsAndHomeLinks(): void
    {
        foreach ([
            ['var/www/error-401.html', '/401_images/401-', 3, true],
            ['var/www/error-403.html', '/404_images/404-', 32, false],
            ['var/www/error-404.html', '/404_images/404-', 32, true],
            ['var/www/error-502.html', '/502_images/502-', 13, true],
        ] as [$path, $prefix, $count, $hasHomeLink]) {
            $contents = $this->assertErrorPageImagePool($path, $prefix, $count);
            if ($hasHomeLink) {
                $this->assertStringContainsString('<a href="/">Return to the main page.</a>', $contents);
            }
        }
    }

    public function testErrorPageSourceContractsStayReadable(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'var/www/error-401.html' => ['required' => [
                '401 - Authentication Required',
                'Enter your PMSS username',
                'refresh this page to try again',
                '<a href="/">Return to the main page.</a>',
            ]],
            'var/www/error-403.html' => ['required' => [
                '403 - Forbidden',
                'The sage guards this path.',
                '<a href="/">Return to the main page.</a>',
            ]],
            'var/www/error-502.html' => ['required' => ['Your disk quota is full', 'connect with SFTP and delete files', 'account is suspended', 'server-wide storage pressure']],
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
                'required' => ['Account Suspended', 'https://pulsedmedia.com/contact/', 'class="cta"', '.cta:visited', 'color:#fff;'],
                'forbidden' => ['<img'],
            ],
        ]);
    }

    public function testNginxTemplatesUsePerUser502FallbackPages(): void
    {
        require_once dirname(__DIR__, 3).'/lib/nginxConfig/templates.php';

        foreach ([
            'user template' => [$this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'), '##username', 3],
            'private subdomain template' => [\pmssNginxUserSubdomainTemplates()['private'], '##user##', 4],
        ] as $label => [$contents, $token, $count]) {
            $this->assertEquals($count, substr_count($contents, 'error_page 502 /error-502-'.$token.'.html;'), $label);
            $this->assertStringContainsAllStrings(['location = /error-502-'.$token.'.html {', 'try_files $uri /error-502.html;'], $contents, $label.': ');
        }
    }

    private function assertErrorPageImagePool(string $path, string $prefix, int $count): string
    {
        $contents = $this->pmssReadRepoFile($path);
        $this->assertStringContainsAllStrings(['data-error-image-prefix="'.$prefix.'"', 'data-error-image-count="'.$count.'"', '<script src="/error-page.js"></script>'], $contents);
        return $contents;
    }
}
