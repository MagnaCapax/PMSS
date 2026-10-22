<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Panel healing and step2 can deliver OpenClaw callers and installers in either order. */
class OpenClawPanelCompatibilityTest extends TestCase
{
    public function testHealedPanelChecksInstallerCapabilityWithoutHomeEnvironment(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $this->pmssEnsureDir($home.'/bin', 0700);
            $this->pmssEnsureDir($home.'/.openclaw', 0700);
            $this->pmssWriteFile($home.'/.media-stack-port-openclaw', "23000\n");
            $this->pmssWriteFile($home.'/.openclaw/pmss-stopped', '');
            // php-cgi need not export HOME; the compatible installer must use the explicit argument.
            file_put_contents($runRoot.'/php-cli-bootstrap.php', "putenv('HOME');\n", FILE_APPEND);
            $this->pmssWriteFile($home.'/www/openclaw-probe.php', '<?php require __DIR__."/userOpenClaw.php";'
                .'echo json_encode(array(pmssOpenClawRuntimeReady(), pmssOpenClawStatusRead(dirname(__DIR__), "fallback")));');
            $installer = $home.'/bin/install-openclaw';
            $current = $this->pmssReadRepoFile('etc/skel/bin/install-openclaw');
            // An old no-argument callee must never run, even though PHP accepts the caller's extra argument.
            $legacy = '<?php function ocPaths(): array { throw new RuntimeException("HOME must be an existing directory"); }';
            foreach (array('legacy', 'current', 'missing', 'no-path-helper', 'symlink') as $version) {
                if (is_file($installer) || is_link($installer)) unlink($installer);
                if ($version === 'symlink') {
                    symlink($this->pmssRepoPath('etc/skel/bin/install-openclaw'), $installer);
                } elseif ($version !== 'missing') {
                    $this->pmssWriteFile($installer, $version === 'current' ? $current
                        : ($version === 'legacy' ? $legacy : '<?php // Older incomplete installer.'));
                }
                $probe = $render('openclaw-probe.php', array('minBytes' => 1));
                $this->assertSame('', $probe['stderr'], $version);
                $this->assertSame(array($version === 'current', $version === 'current' ? 'off' : 'fallback'),
                    json_decode($probe['stdout'], true), $version);
                foreach (array('welcome.php' => 10000, 'configure.php' => 4000) as $page => $minimum) {
                    $result = $render($page, array('minBytes' => $minimum));
                    $this->assertSame('', $result['stderr'], $version.' '.$page);
                    $this->assertStringContainsString('OpenClaw', $result['stdout']);
                }
            }
        });
    }

    public function testOptionalStatusThrowablesPreserveFallbackAndOtherAppRows(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssEnsureDir($home.'/bin', 0700);
            $this->pmssEnsureDir($home.'/.openclaw', 0700);
            $this->pmssWriteFile($home.'/.media-stack-port-openclaw', "23000\n");
            $this->pmssWriteFile($home.'/.openclaw/gateway.env', '');
            $this->pmssWriteFile($home.'/.openclaw/check.state', 'gave_up 5');
            foreach (array('RuntimeException', 'Error') as $throwable) {
                foreach (array('load', 'status') as $failure) {
                    $throw = 'throw new '.$throwable.'("Optional status failed");';
                    $this->pmssWriteFile($home.'/bin/install-openclaw', '<?php '.($failure === 'load' ? $throw
                        : 'function ocPaths(?string $home = null): array { '.$throw.' }'));
                    foreach (array('welcome.php' => 10000, 'configure.php' => 4000) as $page => $minimum) {
                        $result = $render($page, array('minBytes' => $minimum));
                        $this->assertSame('', $result['stderr'], $failure.' '.$throwable.' '.$page);
                        $this->assertStringContainsString('OpenClaw', $result['stdout']);
                    }
                    $result = $render('welcome.php', array('query' => 'status=1', 'minBytes' => 100));
                    $this->assertSame('', $result['stderr']);
                    $status = json_decode($result['stdout'], true);
                    $this->assertSame('gave up', $status['apps']['openclaw']['state']);
                    $this->assertSame('running', $status['apps']['lighttpd']['state']);
                    $this->assertTrue(isset($status['apps']['rtorrent']));
                    $this->assertStringNotContainsString('Optional status failed', $result['stdout']);
                }
            }
        });
    }
}
