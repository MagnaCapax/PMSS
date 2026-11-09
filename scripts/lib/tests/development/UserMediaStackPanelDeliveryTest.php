<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Readback regressions for the coupled account panel files (Refs #953). */
class UserMediaStackPanelDeliveryTest extends TestCase
{
    private $home;
    private $skel;

    protected function setUp(): void
    {
        $root = $this->pmssMakeTrackedHomeRoot('pmss-media-delivery-');
        $this->home = $root.'/fixtur01';
        $this->skel = $this->pmssMakeTempDir('pmss-media-skel-');
        $this->pmssTrackEnvOverrides(['PMSS_SKEL_DIR' => $this->skel, 'PMSS_LOG_DIR' => $root.'/logs']);
        foreach (['mediaStack.php', 'userMediaStackPanel.php'] as $file) {
            $this->writePair($file, $this->pmssReadRepoFile('etc/skel/www/'.$file));
        }
    }

    public function testCurrentDeliveredPairPasses(): void
    {
        $this->assertTrue($this->check());
    }

    public function testStaleSiblingInEitherDirectionFails(): void
    {
        foreach (['mediaStack.php', 'userMediaStackPanel.php'] as $file) {
            $current = file_get_contents($this->home.'/www/'.$file);
            $this->pmssWriteRelativeFile($this->home, 'www/'.$file, '<?php // stale version');
            $this->assertFalse($this->check());
            $this->assertSame('<?php // stale version', file_get_contents($this->home.'/www/'.$file));
            $this->pmssWriteRelativeFile($this->home, 'www/'.$file, $current);
        }
    }

    public function testMissingSourceOrDeliveredFileFails(): void
    {
        foreach ([$this->skel, $this->home] as $root) {
            foreach (['mediaStack.php', 'userMediaStackPanel.php'] as $file) {
                $path = $root.'/www/'.$file;
                $current = file_get_contents($path);
                unlink($path);
                $this->assertFalse($this->check());
                $this->pmssWriteFile($path, $current);
            }
        }
    }

    public function testMatchingMalformedPhpFailsWithoutLoggingSource(): void
    {
        foreach (['mediaStack.php', 'userMediaStackPanel.php'] as $file) {
            $current = file_get_contents($this->home.'/www/'.$file);
            $this->writePair($file, '<?php function sensitiveFixtureText( {');
            [$ok, $output] = $this->pmssCaptureStdout(function (): bool {
                return \pmssUserMediaStackPanelDeliveryCheck($this->context());
            });
            $this->assertFalse($ok);
            $this->assertStringContainsString('invalid PHP syntax', $output);
            $this->assertStringNotContainsString('sensitiveFixtureText', $output);
            $this->writePair($file, $current);
        }
    }

    public function testLinkedSiblingIsRefusedAndUntouched(): void
    {
        $path = $this->home.'/www/userMediaStackPanel.php';
        unlink($path);
        $this->pmssCreateSymlinkOrSkip($this->skel.'/www/userMediaStackPanel.php', $path);
        $this->assertFalse($this->check());
        $this->assertTrue(is_link($path));
    }

    public function testMatchingOversizedFilesFail(): void
    {
        $this->writePair('userMediaStackPanel.php', str_pad('<?php // ', 262144, 'x'));
        $this->assertTrue($this->check());
        $this->writePair('userMediaStackPanel.php', str_pad('<?php // ', 262145, 'x'));
        $this->assertFalse($this->check());
    }

    public function testCheckNeverExecutesMatchingPhp(): void
    {
        $sentinel = $this->home.'/executed';
        $this->writePair('mediaStack.php', '<?php file_put_contents('.var_export($sentinel, true).', "unsafe");');
        $this->assertTrue($this->check());
        $this->assertFalse(file_exists($sentinel));
    }

    public function testEnvironmentReturnsIncompleteReasonAfterHandlers(): void
    {
        // Export only the controller; its ordinary service work is recorded in memory.
        $script = <<<'PHP'
namespace MediaStackDeliveryFixture;
function pmssBuildUserWebRootContext($user, $sha, &$reason) { return $GLOBALS['ctx']; }
function pmssUserReconcileWebRoot($ctx) { return true; }
function pmssBuildUserContext($user, $sha, &$reason) { return $GLOBALS['ctx']; }
function logmsg($message) {}
function pmssUserEnvironmentHandlers() { return [__NAMESPACE__.'\\fixtureHandler']; }
function fixtureHandler($ctx) { $GLOBALS['handled'] = true; }
function pmssTorrentClientsPostSetupConverge($user, $home) {}
function pmssUserMediaStackPanelDeliveryCheck($ctx) { return \pmssUserMediaStackPanelDeliveryCheck($ctx); }
PHP;
        $script .= 'require '.var_export($this->pmssRepoPath('scripts/lib/update.php'), true).';'
            .'require '.var_export($this->pmssRepoPath('scripts/lib/update/users.php'), true).';'
            .\pmssTestingFunctionExport('pmssUpdateUserEnvironment', 'MediaStackDeliveryFixture');
        $this->pmssWriteRelativeFile($this->home, 'www/userMediaStackPanel.php', '<?php // stale sibling');
        $script .= '$GLOBALS["ctx"] = '.var_export($this->context(), true).'; ob_start();'
            .'$reason = null; $ok = \MediaStackDeliveryFixture\pmssUpdateUserEnvironment("fixtur01", "", $reason); ob_end_clean();'
            .'echo json_encode([$ok, $reason, $GLOBALS["handled"]]);';
        $this->assertSame([false, 'media-stack-panel-delivery-failed', true], $this->pmssRunInlinePhpJson($script));
    }

    private function writePair(string $file, string $source): void
    {
        foreach ([$this->skel, $this->home] as $root) {
            $this->pmssWriteRelativeFile($root, 'www/'.$file, $source);
        }
    }

    private function context(): array
    {
        return ['user' => 'fixtur01', 'home' => $this->home];
    }

    private function check(): bool
    {
        [$ok] = $this->pmssCaptureStdout(function (): bool {
            return \pmssUserMediaStackPanelDeliveryCheck($this->context());
        });
        return $ok;
    }
}
