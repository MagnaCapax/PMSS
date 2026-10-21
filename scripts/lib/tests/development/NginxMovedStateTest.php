<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxConfig/userConfigsGenerate.php';
require_once dirname(__DIR__, 2).'/nginxConfig/templates.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';

/** Render moved and suspended precedence without any live nginx or home files. */
class NginxMovedStateTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-nginx-moved-']; }

    private function context(): array
    {
        foreach (['home/mvdtst/www', 'users', 'conf.d'] as $dir) mkdir($this->tempDir.'/'.$dir, 0755, true);
        $store = new \UserConfigStore($this->tempDir.'/config');
        $this->assertTrue($store->set('mvdtst', ['ramMiB' => 512, 'rtorrentPort' => 5000, 'quota' => 5, 'quotaBurst' => 6, 'movedUntil' => '2026-11-30']));
        $templates = \pmssNginxUserSubdomainTemplates();
        return ['homeBase' => $this->tempDir.'/home', 'nginxUsersDir' => $this->tempDir.'/users',
            'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true, 'subdomainBase' => 'old.example.test',
            'userConfigStore' => $store, 'movedTemplate' => file_get_contents($this->pmssRepoPath('etc/seedbox/config/template.nginx-user-moved')),
            'movedNoticeTemplate' => file_get_contents($this->pmssRepoPath('etc/seedbox/config/template.moved.notice.html')),
            'suspendedTemplate' => 'suspended ##username', 'userTemplate' => 'active ##username ##serverPort',
            'publicSubdomainTemplate' => $templates['public'], 'publicSuspendedTemplate' => $templates['publicSuspended'],
            'publicMovedTemplate' => $templates['publicMoved']];
    }

    public function testMovedRoutesServePrivateStaticNoticeWithoutPorts(): void
    {
        $ctx = $this->context();
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('mvdtst', $ctx, true));
        foreach ([$ctx['nginxUsersDir'].'/mvdtst', $ctx['subdomainConfigDir'].'/pmss-user-mvdtst.conf'] as $path) {
            $rendered = file_get_contents($path);
            $this->assertTrue(strpos($rendered, '2026-11-30') !== false);
            $this->assertFalse(strpos($rendered, 'proxy_pass') !== false);
            $this->assertFalse(strpos($rendered, '##') !== false);
            preg_match_all('/<!DOCTYPE html>.*?<\/html>/s', $rendered, $matches);
            $this->assertSame(2, count($matches[0]));
            foreach ($matches[0] as $html) {
                $this->assertFalse(strpos($html, 'mvdtst') !== false);
                $this->assertFalse(strpos($html, 'old.example.test') !== false);
                $this->assertFalse(strpos($html, '<script') !== false);
                $this->assertTrue(strpos($html, '/css/error-styles.css') !== false);
                $this->assertTrue(strpos($html, 'Your service has moved') !== false);
                $this->assertTrue(strpos($html, 'https://pulsedmedia.com/clients/clientarea.php') !== false);
            }
        }
        $primary = file_get_contents($ctx['nginxUsersDir'].'/mvdtst');
        foreach (['location /user-mvdtst/', 'location /public-mvdtst/'] as $location) $this->assertTrue(strpos($primary, $location) !== false);
        $this->assertSame([], glob($ctx['homeBase'].'/mvdtst/www/*'));
    }

    public function testSuspendedWinsAndClearingRestoresActiveRoute(): void
    {
        $ctx = $this->context();
        mkdir($ctx['homeBase'].'/mvdtst/www-disabled');
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('mvdtst', $ctx, true));
        $this->assertSame('suspended mvdtst', file_get_contents($ctx['nginxUsersDir'].'/mvdtst'));
        rmdir($ctx['homeBase'].'/mvdtst/www-disabled');
        $this->assertTrue($ctx['userConfigStore']->setMovedUntil('mvdtst', null));
        $this->pmssWriteFile($ctx['homeBase'].'/mvdtst/.rtorrent.rc', '');
        $this->pmssWriteFile($ctx['homeBase'].'/mvdtst/.lighttpd.conf', '');
        $ctx['runtimePortDir'] = $this->tempDir.'/ports';
        $this->pmssWriteFile($ctx['runtimePortDir'].'/lighttpd-mvdtst', '15000');
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('mvdtst', $ctx, true));
        $this->assertSame('active mvdtst 15000', file_get_contents($ctx['nginxUsersDir'].'/mvdtst'));
    }

    public function testMissingMovedTemplateFailsWithoutReplacingRoute(): void
    {
        $ctx = $this->context();
        $this->pmssWriteFile($ctx['nginxUsersDir'].'/mvdtst', 'keep');
        $ctx['movedNoticeTemplate'] = false;
        $this->assertSame(PMSS_NGINX_USER_CONFIG_WRITE_FAILED, \pmssCreateNginxConfigGenerateUser('mvdtst', $ctx, true));
        $this->assertSame('keep', file_get_contents($ctx['nginxUsersDir'].'/mvdtst'));
    }

    public function testMovedHtmlQuotesStayInsideNginxResponseString(): void
    {
        $expected = <<<'NGINX'
It\'s \$host \\ safe 2026-11-30
NGINX;
        $this->assertSame($expected, \pmssNginxMovedPage("It's \$host \\ safe ##movedUntil", '2026-11-30'));
        foreach (['2026-02-30', '2026-1-1', '2026-11-30;return 200 x'] as $date) {
            $rejected = false;
            try { \pmssNginxMovedPage('date ##movedUntil', $date); }
            catch (\RuntimeException $error) { $rejected = true; }
            $this->assertTrue($rejected);
        }
    }
}
