<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/nginxConfig/customerHosts.php';
require_once dirname(__DIR__, 3).'/lib/nginxConfig/customerHostConfigs.php';
require_once dirname(__DIR__, 3).'/lib/nginxConfig/userConfigsGenerate.php';
require_once dirname(__DIR__, 3).'/lib/lighttpd/configRender.php';

class CustomerHostServingTest extends TestCase
{
    public function testDocrootDefaultsToPublicWhenFileIsMissing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-customer-host-home-', 0700);

        $this->assertSame('public', \pmssUserCustomerHostDocrootSubdirRead($home));
    }

    public function testDocrootAcceptsSafeNestedSubdir(): void
    {
        $home = $this->pmssMakeTempDir('pmss-customer-host-home-', 0700);
        $this->pmssEnsureDir($home.'/www');
        file_put_contents($home.'/www/.mcx-docroot', "sites/main_v2\nignored\n");

        $this->assertSame('sites/main_v2', \pmssUserCustomerHostDocrootSubdirRead($home));
    }

    public function testDocrootRejectsUnsafeValues(): void
    {
        foreach (['', '../private', '/tmp', 'public/../private', '.ssh', 'site name', "bad\0path", 'public//site'] as $raw) {
            $this->assertSame(null, \pmssUserCustomerHostDocrootSubdirNormalize($raw), 'expected invalid: '.str_replace("\0", '\0', $raw));
        }
    }

    public function testCustomerHostMapConsumerIsStubbedUntilEndpointSliceExists(): void
    {
        $map = \pmssNginxCustomerHostMapLoad('seedbox.example.com');

        $this->assertSame(false, $map['loaded']);
        $this->assertSame([], $map['hostsByUser']);
        $this->assertStringContainsAllStrings(['pulsedmedia.com/remote/mcxData-api.php', 'per-server slice', 'external hostname FQDN', 'local PMSS username'], $map['message']);
    }

    public function testCustomerHostTemplateRendersHttpOnlyProxyToUserLighttpd(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-customer-host');
        $rendered = \pmssNginxCustomerHostTemplateRender($template, 'alice', ['ABCDEF1234567890.mcx.fi', 'www.example.net'], 31234, 'sites/main');

        $this->assertStringContainsAllStrings([
            'listen 80;',
            'server_name abcdef1234567890.mcx.fi www.example.net;',
            'proxy_pass http://127.0.0.1:31234/_pmss-customer-host-docroot/;',
            'Document root: /home/alice/www/sites/main/',
            'include /etc/nginx/proxy_params;',
        ], $rendered);
        $this->assertStringNotContainsString('listen 443', $rendered);
    }

    public function testCustomerHostWriterStoresPerUserConfig(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-customer-host-conf-', 0700);
        $ctx = [
            'subdomainConfigDir' => $dir,
            'customerHostTemplate' => 'server ##username## ##serverNames## ##serverPort## ##docrootSubdir## ##lighttpdAlias##',
        ];

        $ok = \pmssCreateNginxConfigWriteCustomerHostConfig($ctx, 'alice', ['alice.mcx.fi'], false, 30000, 'public');

        $this->assertTrue($ok);
        $this->assertSame('server alice alice.mcx.fi 30000 public /_pmss-customer-host-docroot/', (string) file_get_contents($dir.'/pmss-customer-host-alice.conf'));
    }

    public function testSuspendedCustomerHostWriterUsesSuspendedTemplate(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-customer-host-suspended-', 0700);
        $ctx = [
            'subdomainConfigDir' => $dir,
            'customerHostSuspendedTemplate' => 'suspended ##username## ##serverNames##',
        ];

        $ok = \pmssCreateNginxConfigWriteCustomerHostConfig($ctx, 'alice', ['alice.mcx.fi'], true);

        $this->assertTrue($ok);
        $this->assertSame('suspended alice alice.mcx.fi', (string) file_get_contents($dir.'/pmss-customer-host-alice.conf'));
    }

    public function testLighttpdRenderUsesCustomerHostDocrootAlias(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $rendered = \pmssLighttpdRenderUserConfig(
            $template,
            'alice',
            31234,
            0,
            0,
            ['maxProcs' => 1, 'children' => 3],
            'sites/main'
        );

        $this->assertStringContainsAllStrings([
            '# PMSS customer host docroot: sites/main',
            '"/_pmss-customer-host-docroot/" => "/home/alice/www/sites/main/"',
            '"/public-alice/" => "/home/alice/www/public/"',
        ], $rendered);
    }

    public function testMcxUnmatchedTemplateReturns404WithoutDefaultServer(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-mcx-fi-unmatched');

        $this->assertStringContainsAllStrings(['listen 80;', 'server_name .mcx.fi;', 'return 404;'], $template);
        $this->assertStringNotContainsString('default_server', $template);
    }
}
