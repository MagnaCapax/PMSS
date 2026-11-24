<?php
/** Verify rc.local respects the phase-2 root-cron hold and final restore ordering. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronUpdateHoldTest extends TestCase
{
    public function testRcLocalRestoresCronOnlyWithoutAnUpdateHold(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.rc.local');
        // Execute only the cron block, with every host path redirected to private fixtures.
        $this->assertSame(1, preg_match(
            '~^if \[ -f /etc/seedbox/config/root\.cron \].*?^fi$~ms',
            $template,
            $matches
        ));

        foreach ([false, true] as $hasTemplate) {
            foreach ([false, true] as $hasHold) {
                foreach ([false, true] as $hasTarget) {
                    $root = $this->pmssMakeTempDir('pmss-cron-update-hold-');
                    $paths = [
                        '/etc/seedbox/config/root.cron' => $root.'/root.cron',
                        '/run/pmss-root-cron-disabled' => $root.'/hold',
                        '/etc/cron.d/pmss' => $root.'/cron',
                    ];
                    if ($hasTemplate) {
                        $this->pmssWriteFile($root.'/root.cron', "# updated cron fixture\n");
                    }
                    if ($hasHold) {
                        $this->pmssWriteFile($root.'/hold', "update\n");
                    }
                    if ($hasTarget) {
                        $this->pmssWriteFile($root.'/cron', "# existing cron fixture\n");
                    }

                    $script = strtr($matches[0], array_map('escapeshellarg', $paths));
                    foreach (array_keys($paths) as $hostPath) {
                        $this->assertStringNotContainsString($hostPath, $script);
                    }
                    $result = $this->pmssExecArgvCommand(['bash', '-c', $script]);
                    $this->assertSame(0, $result['rc']);
                    $this->assertSame('', $result['output']);
                    $restored = $hasTemplate && !$hasHold;
                    clearstatcache();
                    $this->assertSame($restored || $hasTarget, is_file($root.'/cron'));
                    $this->assertSame($hasHold, is_file($root.'/hold'));
                    if ($restored || $hasTarget) {
                        $this->assertSame(
                            $restored ? "# updated cron fixture\n" : "# existing cron fixture\n",
                            $this->pmssReadFileOrEmpty($root.'/cron')
                        );
                    }
                    if ($restored) {
                        $this->assertSame(0644, fileperms($root.'/cron') & 0777);
                    }
                }
            }
        }
    }

    public function testUpdateHoldsCronBeforeRemovalAndRestoresAfterIdleWork(): void
    {
        $bootstrap = $this->pmssReadRepoFile('scripts/update.php');
        $start = strpos($bootstrap, 'function pmssDisableRootCronForUpdateStep2()');
        $this->assertTrue($start !== false);
        // Scope the assertion to disabling: the bootstrap also contains earlier restore paths.
        $this->assertOrderedStrings([
            'pmssRegisterRootCronRestoreGuard();',
            "@file_put_contents('/run/pmss-root-cron-disabled'",
            "pmssRemoveFileFatal(\$target, 'root cron file');",
        ], substr($bootstrap, $start));
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/update-step2.php', [
            "['Applying runtime service templates', 'pmssApplyRuntimeTemplates'",
            "'Running post-install storage benchmark', 'pmssStorageBenchmarkPostInstallRun'",
            "runStep('Restoring direct BFQ weights before root cron restore'",
            "runStep('Refreshing root cron configuration', '/scripts/util/setupRootCron.php');",
        ]);
    }
}
