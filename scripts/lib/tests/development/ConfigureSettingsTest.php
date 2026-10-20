<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';

/** Hermetic account settings and password boundary checks. */
class ConfigureSettingsTest extends TestCase
{
    public function testPikkumiesInterfaceOnlySavesPrivateKnownModes(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-choice-');
        $path = $home.'/.config/pmss/ui/pikkumies-interface';
        $this->assertFalse(\pmssPikkumiesInterfaceSave($home, 'unknown'));
        $this->assertFalse(file_exists($path));
        $this->assertTrue(\pmssPikkumiesInterfaceSave($home, 'power-user'));
        $this->assertSame("power-user\n", file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(0700, fileperms(dirname($path)) & 0777);
        $this->assertTrue(\pmssPikkumiesInterfaceSave($home, 'simplified'));
        $this->assertSame("simplified\n", file_get_contents($path));
        unlink($path);
        symlink($home.'/outside', $path);
        $this->assertFalse(\pmssPikkumiesInterfaceSave($home, 'power-user'));
        $this->assertFalse(file_exists($home.'/outside'));
    }

    private function home(): string
    {
        $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-configure-home-').'/alice');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $hash = crypt('secret', '$6$rounds=5000$pmssconfigure$');
        $this->pmssWriteFile($home.'/.lighttpd/.htpasswd', 'alice:'.$hash."\n");
        return $home;
    }

    public function testHighImpactRequiresCurrentAccountPassword(): void
    {
        $home = $this->home();
        $path = $home.'/.rtorrent.rc.custom';
        $this->pmssWriteFile($path, "old\n");
        foreach (array(array(), array('password' => 'wrong')) as $credentials) {
            $result = \pmssConfigureActionApply($home, 'rtorrentCustom', $credentials + array('content' => 'new'));
            $this->assertSame(403, $result['status']);
            $this->assertSame('The password you entered was not accepted. Nothing was changed.', $result['message']);
            $this->assertSame("old\n", file_get_contents($path));
        }
        $result = \pmssConfigureActionApply($home, 'rtorrentCustom', array('password' => 'secret', 'content' => "new\n"));
        $this->assertTrue($result['ok']);
        $this->assertSame("new\n", file_get_contents($path));
        $this->assertSame("old\n", file_get_contents($path.'.prev'));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertTrue(\pmssConfigureActionApply($home, 'rtorrentRestore', array('password' => 'secret'))['ok']);
        $this->assertSame("old\n", file_get_contents($path));
        $this->assertSame("new\n", file_get_contents($path.'.prev'));
        $this->assertStringNotContainsString('secret', file_get_contents($home.'/.lighttpd/error.log'));
    }

    public function testCustomFramesRejectUnsafeInputAndKeepComments(): void
    {
        $valid = array(array('label' => 'Guide', 'tooltip' => '', 'url' => 'https://example.com'));
        $this->assertSame("# keep\ncustom0||Guide|https://example.com\n", \pmssConfigureFramesBuild($valid, "# keep\nold|tip|Old|/old\n"));
        foreach (array('javascript:alert(1)', 'data:text/plain,x', 'vbscript:msgbox(1)', '//host', '../secret', '/a/../b') as $url) {
            $rows = $valid;
            $rows[0]['url'] = $url;
            $this->assertSame(null, \pmssConfigureFramesBuild($rows, ''));
        }
        foreach (array('bad|label', "bad\nlabel", "bad\rlabel") as $label) {
            $rows = $valid;
            $rows[0]['label'] = $label;
            $this->assertSame(null, \pmssConfigureFramesBuild($rows, ''));
        }
        foreach (array('', str_repeat('x', 41)) as $label) {
            $rows = $valid;
            $rows[0]['label'] = $label;
            $this->assertSame(null, \pmssConfigureFramesBuild($rows, ''));
        }
        $rows = $valid;
        $rows[0]['tooltip'] = str_repeat('x', 81);
        $this->assertSame(null, \pmssConfigureFramesBuild($rows, ''));
        $this->assertSame(null, \pmssConfigureFramesBuild(array_fill(0, 33, $valid[0]), ''));
        $this->assertSame(null, \pmssConfigureFramesBuild($valid, '#'.str_repeat('x', 4096)));
    }

    public function testCustomFramesActionRequiresPasswordAndPreservesComments(): void
    {
        $home = $this->home();
        $path = $home.'/.customFrames';
        $this->pmssWriteFile($path, "# retained\nold|Old|Old|/old\n");
        $request = array('rows' => array(array('label' => 'Docs', 'tooltip' => 'Help', 'url' => '/docs')));
        $this->assertSame(403, \pmssConfigureActionApply($home, 'customFrames', $request)['status']);
        $this->assertSame(403, \pmssConfigureActionApply($home, 'customFrames', $request + array('password' => 'wrong'))['status']);
        $this->assertSame("# retained\nold|Old|Old|/old\n", file_get_contents($path));
        $result = \pmssConfigureActionApply($home, 'customFrames', $request + array('password' => 'secret'));
        $this->assertTrue($result['ok']);
        $this->assertSame("# retained\ncustom0|Help|Docs|/docs\n", file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testRtorrentBoundsAndSymlinkTarget(): void
    {
        $home = $this->home();
        $path = $home.'/.rtorrent.rc.custom';
        $password = array('password' => 'secret');
        $this->assertFalse(\pmssConfigureActionApply($home, 'rtorrentCustom', $password + array('content' => str_repeat('a', 16385)))['ok']);
        $this->assertFalse(\pmssConfigureActionApply($home, 'rtorrentCustom', $password + array('content' => "a\0b"))['ok']);
        $outside = $this->pmssWriteFile($home.'/outside', 'untouched');
        $this->pmssCreateSymlinkOrSkip($outside, $path);
        $this->assertFalse(\pmssConfigureActionApply($home, 'rtorrentCustom', $password + array('content' => 'new'))['ok']);
        $this->assertSame('untouched', file_get_contents($outside));
    }

    public function testCertificateModesAndFailureMarker(): void
    {
        $home = $this->home();
        $this->assertFalse(\pmssConfigureActionApply($home, 'webCertsDefault', array())['ok']);
        $this->pmssWriteFile($home.'/.billingId', "42\n");
        $this->assertSame('42', \pmssConfigureBillingIdRead($home));
        $this->pmssWriteFile($home.'/.billingServiceId', "123\n");
        $this->assertSame('123', \pmssConfigureBillingIdRead($home));
        $failed = $home.'/.request-web-certs.failed';
        $this->pmssWriteFile($failed, 'failed');
        $this->assertTrue(\pmssConfigureActionApply($home, 'webCertsDefault', array())['ok']);
        $this->assertSame("default\n", file_get_contents($home.'/.request-web-certs'));
        $this->assertFalse(file_exists($failed));
        $this->assertSame(403, \pmssConfigureActionApply($home, 'webCertsUsername', array('ctAcknowledged' => 1))['status']);
        $this->assertSame(403, \pmssConfigureActionApply($home, 'webCertsUsername', array('ctAcknowledged' => 1, 'password' => 'wrong'))['status']);
        $this->assertSame("default\n", file_get_contents($home.'/.request-web-certs'));
        $this->assertTrue(\pmssConfigureActionApply($home, 'webCertsUsername', array('ctAcknowledged' => 1, 'password' => 'secret'))['ok']);
        $this->assertSame("username\n", file_get_contents($home.'/.request-web-certs'));
    }

    public function testNonXhrPostIsRejectedBeforeSettingsDispatch(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home): void {
            $php = 'chdir('.var_export($home.'/www', true).');'
                .'$_SERVER["REQUEST_METHOD"]="POST"; $_POST["action"]="webCertsDefault";'
                .'register_shutdown_function(function(){echo "HTTP=".http_response_code();});'
                .'require '.var_export($home.'/www/configure.php', true).';';
            $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($php));
            $this->assertSame(0, $result['rc']);
            $this->assertStringContainsString('HTTP=403', $result['output']);
            $this->assertFalse(file_exists($home.'/.request-web-certs'));
        });
    }
}
