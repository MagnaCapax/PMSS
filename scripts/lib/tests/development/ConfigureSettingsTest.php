<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureSettings.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureAppOwnerSettings.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

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

    /** Keep account authentication separate from the external credential fixture. */
    private function slskdRequest(array $changes = array()): array
    {
        return array_replace(array('password' => 'secret', 'username' => 'network user',
            'soulseekPassword' => 'network-private-password', 'sharedDir' => ''), $changes);
    }

    public function testSlskdSettingsRequireAccountPassword(): void
    {
        $home = $this->home();
        foreach (array(null, 'wrong', array('secret')) as $password) {
            $result = \pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest(array('password' => $password)));
            $this->assertSame(403, $result['status']);
            $this->assertFalse(file_exists(\pmssSlskdEnvPath($home)));
        }
        $this->assertStringNotContainsString('network-private-password', file_get_contents($home.'/.lighttpd/error.log'));
    }

    public function testSlskdSettingsWritePrivateAtomicFileInExactFormat(): void
    {
        $home = $this->home();
        $one = $this->pmssEnsureDir($home.'/music one');
        $two = $this->pmssEnsureDir($home.'/music \'$" two');
        $request = $this->slskdRequest(array('username' => "  network 'name  ",
            'soulseekPassword' => 'network \'$" ; $(touch forbidden) `id` \\ secret', 'sharedDir' => $one.';'.$two));
        $result = \pmssConfigureActionApply($home, 'slskdSettings', $request);
        $this->assertTrue($result['ok']);
        $expected = "SLSKD_SLSK_USERNAME='network '\\''name'\n"
            ."SLSKD_SLSK_PASSWORD='network '\\''\$\" ; \$(touch forbidden) `id` \\ secret'\n"
            ."SLSKD_SHARED_DIR='".$one.';'.$home."/music '\\''\$\" two'\n";
        $path = \pmssSlskdEnvPath($home);
        $this->assertSame($expected, file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(posix_geteuid(), fileowner($path));
        foreach (array($home.'/.config', dirname($path)) as $dir) $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertSame(array(), glob(dirname($path).'/.pmss-configure-*'));
        $inode = fileinode($path);
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        clearstatcache(true, $path);
        $this->assertTrue($inode !== fileinode($path));
        $this->assertSame($expected, file_get_contents($path.'.prev'));
        $this->assertSame(0600, fileperms($path.'.prev') & 0777);
    }

    public function testSlskdSettingsRejectInvalidInput(): void
    {
        $home = $this->home();
        $request = $this->slskdRequest();
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $path = \pmssSlskdEnvPath($home);
        $old = file_get_contents($path);
        $cases = array(array('username' => ''), array('username' => str_repeat('x', 65)),
            array('username' => "\nname"), array('username' => array()), array('username' => "bad\xff"),
            array('soulseekPassword' => "bad\0secret"), array('soulseekPassword' => "bad\rsecret"),
            array('soulseekPassword' => "bad\nsecret"), array('soulseekPassword' => str_repeat('x', 257)),
            array('soulseekPassword' => 'secret'), array('soulseekPassword' => array()),
            array('sharedDir' => 'relative/path'), array('sharedDir' => $home.'/missing'),
            array('sharedDir' => array()), array('sharedDir' => null), array('sharedDir' => implode(';', array_fill(0, 33, $home))),
            array('sharedDir' => $home."\n"));
        // Existing, long absolute directories exercise the aggregate 4096-byte bound.
        $long = $this->pmssEnsureDir($home.'/'.str_repeat('d', 180));
        $cases[] = array('sharedDir' => implode(';', array_fill(0, 24, $long)));
        foreach ($cases as $changes) {
            $result = \pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest($changes));
            $this->assertSame(400, $result['status']);
            $this->assertSame($old, file_get_contents($path));
            $this->assertFalse(file_exists($path.'.prev'));
        }
        $fresh = $this->home();
        $this->assertSame(400, \pmssConfigureActionApply($fresh, 'slskdSettings',
            $this->slskdRequest(array('soulseekPassword' => '')))['status']);
        $this->assertFalse(file_exists($fresh.'/.config'));
    }

    public function testSlskdSettingsRefuseSymlinkTargets(): void
    {
        foreach (array('config', 'slskd', 'env', 'directory', 'previous') as $case) {
            $home = $this->home();
            $outside = $this->pmssEnsureDir($home.'/outside');
            $target = $this->pmssWriteFile($outside.'/untouched', 'keep');
            $path = \pmssSlskdEnvPath($home);
            if ($case === 'config') symlink($outside, $home.'/.config');
            elseif ($case === 'slskd') {
                $this->pmssEnsureDir($home.'/.config');
                symlink($outside, dirname($path));
            } else {
                $this->pmssEnsureDir(dirname($path));
                if ($case === 'directory') mkdir($path);
                else symlink($target, $case === 'previous' ? $path.'.prev' : $path);
            }
            $this->assertFalse(\pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest())['ok'], $case);
            $this->assertSame('keep', file_get_contents($target));
            $this->assertSame(array('untouched'), array_values(array_diff(scandir($outside), array('.', '..'))));
        }
    }

    public function testSlskdSettingsBlankPasswordKeepsStoredOnlyForOwnFormat(): void
    {
        $home = $this->home();
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest())['ok']);
        $path = \pmssSlskdEnvPath($home);
        $request = $this->slskdRequest(array('username' => 'changed', 'soulseekPassword' => ''));
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $parsed = \pmssSlskdEnvParse(file_get_contents($path));
        $this->assertSame('network-private-password', $parsed['SLSKD_SLSK_PASSWORD']);
        $this->assertSame('changed', $parsed['SLSKD_SLSK_USERNAME']);
        $custom = "# owner content\nSLSKD_SLSK_PASSWORD='custom-secret'\n";
        file_put_contents($path, $custom);
        $this->assertTrue(\pmssSlskdSettingsState($home)['custom']);
        $this->assertSame(400, \pmssConfigureActionApply($home, 'slskdSettings', $request)['status']);
        $this->assertSame($custom, file_get_contents($path));
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest())['ok']);
        $this->assertSame($custom, file_get_contents($path.'.prev'));
        $this->assertSame(0600, fileperms($path.'.prev') & 0777);
        // Retaining an external password must still enforce primary credential separation.
        file_put_contents($home.'/.lighttpd/.htpasswd', basename($home).':'.crypt('network-private-password', '$6$rounds=5000$pmssconfigure$')."\n");
        $request['password'] = 'network-private-password';
        $this->assertSame(400, \pmssConfigureActionApply($home, 'slskdSettings', $request)['status']);
    }

    public function testSlskdSettingsResponseNeverContainsPassword(): void
    {
        $home = $this->home();
        $result = \pmssConfigureActionApply($home, 'slskdSettings', $this->slskdRequest());
        $state = \pmssSlskdSettingsState($home);
        $this->assertSame(array('configured' => true, 'custom' => false, 'username' => 'network user',
            'sharedDir' => '', 'passwordSet' => true), $state);
        $this->assertStringNotContainsString('network-private-password', json_encode(array($result, $state)));
        $this->assertStringNotContainsString('network-private-password', file_get_contents($home.'/.lighttpd/error.log'));
    }

    public function testSlskdParserRejectsNoncanonicalOrExecutableContent(): void
    {
        $valid = "SLSKD_SLSK_USERNAME='user'\nSLSKD_SLSK_PASSWORD='pass'\n";
        $this->assertSame('pass', \pmssSlskdEnvParse($valid)['SLSKD_SLSK_PASSWORD']);
        foreach (array('', rtrim($valid), $valid."\n", "# comment\n".$valid, $valid."OTHER='value'\n",
            $valid."SLSKD_SLSK_PASSWORD='again'\n", "SLSKD_SLSK_PASSWORD='pass'\nSLSKD_SLSK_USERNAME='user'\n",
            str_replace("'pass'", '"pass"', $valid), str_replace("'pass'", "'pa'ss'", $valid),
            str_replace("'pass'", "'pass'; id", $valid), str_replace("'pass'", "'bad\0secret'", $valid),
            str_replace("'pass'", "'bad\xff'", $valid), str_repeat('x', 4097)) as $custom) {
            $this->assertSame(null, \pmssSlskdEnvParse($custom));
        }
    }

    public function testSlskdSettingsRestartSignalOnlyWhenEnabled(): void
    {
        $home = $this->home();
        $request = $this->slskdRequest();
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $this->assertSame('', \pmssUserWatchdogRestartToken($home, 'slskd'));
        $marker = $home.'/.slskdEnable';
        symlink($this->pmssWriteFile($home.'/outside', 'enabled'), $marker);
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $this->assertSame('', \pmssUserWatchdogRestartToken($home, 'slskd'));
        unlink($marker);
        // Fail before the save could signal anything on a VM with an actual native slskd.
        $this->assertSame(array(), \pmssCustomerNativePidsRead(\pmssCustomerManagedAppDefinitions()['slskd']['binaries']));
        $this->pmssWriteFile($marker, '');
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $first = \pmssUserWatchdogRestartToken($home, 'slskd');
        $this->assertTrue($first !== '');
        $this->assertTrue(\pmssConfigureActionApply($home, 'slskdSettings', $request)['ok']);
        $this->assertTrue(\pmssUserWatchdogRestartToken($home, 'slskd') !== $first);
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
        $this->assertSame(403, \pmssConfigureActionApply($home, 'pikkumiesHermesShowLogin', array('password' => 'wrong'))['status']);
    }

    public function testPikkumiesHermesPasswordReaderRejectsLooseOrMalformedFiles(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-login-', 0700);
        $path = $home.'/.mcxPikkumies/pikkumies/local/ui/hermes/credentials';
        $this->pmssEnsureDir(dirname($path));
        $this->assertSame('', \pmssPikkumiesHermesLoginRead($home));
        $content = 'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD='.str_repeat('a', 64)."\n"
            .'HERMES_DASHBOARD_BASIC_AUTH_SECRET='.str_repeat('b', 64)."\n";
        file_put_contents($path, $content);
        chmod($path, 0600);
        $this->assertSame(str_repeat('a', 64), \pmssPikkumiesHermesLoginRead($home));
        chmod($path, 0644);
        $this->assertSame('', \pmssPikkumiesHermesLoginRead($home));
        chmod($path, 0600);
        file_put_contents($path, $content.'EXTRA=1' . "\n");
        $this->assertSame('', \pmssPikkumiesHermesLoginRead($home));
        unlink($path);
        symlink($home.'/missing', $path);
        $this->assertSame('', \pmssPikkumiesHermesLoginRead($home));
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

    public function testCustomFramesPositionAddsFifthFieldOnlyWhenSet(): void
    {
        $row = array('label' => 'Guide', 'tooltip' => 'Help', 'url' => 'https://example.com');
        $this->assertSame("custom0|Help|Guide|https://example.com|10\n",
            \pmssConfigureFramesBuild(array($row + array('position' => '10')), ''));
        $this->assertSame("custom0|Help|Guide|https://example.com|-5\n",
            \pmssConfigureFramesBuild(array($row + array('position' => ' -5 ')), ''));
        // An empty or absent position keeps the 4-field line byte-for-byte, so older readers see no change.
        $legacy = "custom0|Help|Guide|https://example.com\n";
        $this->assertSame($legacy, \pmssConfigureFramesBuild(array($row), ''));
        $this->assertSame($legacy, \pmssConfigureFramesBuild(array($row + array('position' => '')), ''));
        $this->assertSame($legacy, \pmssConfigureFramesBuild(array($row + array('position' => array('10'))), ''));
        foreach (array('x', '100', '-100', '1.5', '+5', '5x') as $position) {
            $this->assertSame(null, \pmssConfigureFramesBuild(array($row + array('position' => $position)), ''), $position);
        }
    }

    public function testCustomFramesRebuildKeepsPositionOnlyLinesAndComments(): void
    {
        $existing = "# keep\nopenclaw||||50\npikkumies||||-5\nold|tip|Old|/old|7\nnotaposition||||100\n";
        $rows = array(array('label' => 'Guide', 'tooltip' => '', 'url' => 'https://example.com', 'position' => '3'));
        $this->assertSame("# keep\nopenclaw||||50\npikkumies||||-5\ncustom0||Guide|https://example.com|3\n",
            \pmssConfigureFramesBuild($rows, $existing));
        $this->assertSame("# keep\nopenclaw||||50\npikkumies||||-5\n", \pmssConfigureFramesBuild(array(), $existing));
    }

    public function testSettingsStateReadsPositionAndSkipsPositionOnlyLines(): void
    {
        $home = $this->home();
        $this->pmssWriteFile($home.'/.customFrames', "# comment\nopenclaw||||50\ncustom0|Help|Docs|/docs|10\n"
            ."custom1|Tip|Guide|https://example.com\ncustom2|Odd|Odd|/odd|abc\n");
        $this->assertSame(array(
            array('tooltip' => 'Help', 'label' => 'Docs', 'url' => '/docs', 'position' => '10'),
            array('tooltip' => 'Tip', 'label' => 'Guide', 'url' => 'https://example.com', 'position' => ''),
            array('tooltip' => 'Odd', 'label' => 'Odd', 'url' => '/odd', 'position' => ''),
        ), \pmssConfigureSettingsStateRead($home)['customRows']);
    }

    public function testCustomFramesActionSavesPositionAndKeepsPositionOnlyLines(): void
    {
        $home = $this->home();
        $path = $home.'/.customFrames';
        $this->pmssWriteFile($path, "# retained\nopenclaw||||50\nold|Old|Old|/old\n");
        $request = array('password' => 'secret', 'rows' => array(array('label' => 'Docs', 'tooltip' => 'Help', 'url' => '/docs', 'position' => '20')));
        $this->assertTrue(\pmssConfigureActionApply($home, 'customFrames', $request)['ok']);
        $this->assertSame("# retained\nopenclaw||||50\ncustom0|Help|Docs|/docs|20\n", file_get_contents($path));
        $request['rows'][0]['position'] = '100';
        $this->assertFalse(\pmssConfigureActionApply($home, 'customFrames', $request)['ok']);
        $this->assertSame("# retained\nopenclaw||||50\ncustom0|Help|Docs|/docs|20\n", file_get_contents($path));
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
