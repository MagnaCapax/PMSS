<?php
namespace PMSS\Tests;

/** Freeze notice bytes independently of the runner's operator-first includes. */
final class StorageHealthNoticeContractTest extends TestCase
{
    public function testOperatorAndCustomerEntrypointsPreserveNoticeSnapshot(): void
    {
        $degraded = <<<'HTML'
<div class="pmss-raid-notice pmss-raid-notice-error" role="alert" aria-live="assertive">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#9940;</span> Storage array degraded</strong>
    <p>A drive in your server's storage array has failed. Your data is still accessible but the array is running without full redundancy. Please contact support if you experience issues.</p>
</div>
HTML;
        $maintenance = <<<'HTML'
<div class="pmss-raid-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Home storage maintenance in progress</strong>
    <p>The <code>/home</code> RAID array md0 is running a check. Disk performance is temporarily lower.</p>
__EMPTY_DETAIL__
    <p>If possible, avoid heavy disk activity until the array work completes. Lighter use helps the maintenance finish sooner.</p>
</div>
HTML;
        $maintenance = str_replace('__EMPTY_DETAIL__', '    ', $maintenance);
        $escaped = <<<'HTML'
<div class="pmss-raid-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Home storage maintenance in progress</strong>
    <p>The <code>/home</code> RAID array md&lt;&amp;&quot;&#039; is running a resync&lt;&amp;&quot;&#039;. Disk performance is temporarily lower.</p>
    <div class="pmss-raid-meta">Progress: 12.3% <span aria-hidden="true">&bull;</span> ETA: &lt;15min <span aria-hidden="true">&bull;</span> Speed: 1&amp;2K/sec</div>
    <p>If possible, avoid heavy disk activity until the array work completes. Lighter use helps the maintenance finish sooner.</p>
</div>
HTML;
        $expected = [
            'notices' => ['', '', '', $degraded, $maintenance, $escaped,
                str_replace('a check.', 'a reshape.', $maintenance),
                str_replace('array md0 ', 'array  ', $maintenance)],
            'summaries' => [
                ['operation' => '', 'progress' => '', 'eta' => '', 'speed' => ''],
                ['operation' => 'reshape', 'progress' => '', 'eta' => '', 'speed' => ''],
                ['operation' => 'check', 'progress' => '12.3%', 'eta' => '15.4min', 'speed' => '123456K/sec'],
                ['operation' => 'recovery', 'progress' => '0.0%', 'eta' => 'none', 'speed' => '0K/sec'],
                ['operation' => '', 'progress' => '20%', 'eta' => '', 'speed' => ''],
            ],
        ];
        foreach (['operator', 'customer', 'operator,customer'] as $order) {
            $result = $this->pmssRunRepoPhpScriptCommand('scripts/lib/tests/fixtures/storageHealthNoticeContract.php', [$order]);
            $this->assertSame(0, $result['rc']);
            $this->assertSame($expected, json_decode($result['output'], true), $order);
        }
    }

    public function testPartialCustomerDeliveryDoesNotExposeIncompleteNoticeApi(): void
    {
        $modules = ['storageHealthRaidDisplay', 'storageHealthRaid', 'storageHealthPressure', 'storageHealthDisplay'];
        foreach ($modules as $missing) {
            $directory = $this->pmssMakeTempDir('pmss-storage-partial-');
            $this->pmssWriteRelativeFile($directory, 'scriptsInc.php', '<?php');
            foreach (array_merge($modules, ['storageHealthNotice']) as $module) {
                if ($module === $missing) continue;
                $this->pmssWriteRelativeFile($directory, $module.'.php',
                    file_get_contents($this->pmssRepoPath('etc/skel/www/'.$module.'.php')));
            }
            $result = $this->pmssRunRepoPhpScriptCommand('scripts/lib/tests/fixtures/storageHealthNoticeContract.php',
                ['partial', $directory.'/storageHealthNotice.php']);
            $this->assertSame(0, $result['rc']);
            $this->assertSame('[false,false,false]', $result['output'], $missing);
        }
    }

    public function testOperatorBridgeRefusesMissingOrSymlinkedSkeletonHelper(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-storage-bridge-');
        $source = file_get_contents($this->pmssRepoPath('scripts/lib/storageHealth/raid.php'));
        $source = str_replace("__DIR__.'/common.php'", var_export($this->pmssRepoPath('scripts/lib/storageHealth/common.php'), true), $source);
        $script = $this->pmssWriteRelativeFile($directory, 'scripts/lib/storageHealth/raid.php', $source);
        $helper = $directory.'/etc/skel/www/storageHealthRaidDisplay.php';
        foreach ([false, true] as $symlink) {
            if ($symlink) {
                $this->pmssEnsureDir(dirname($helper));
                $this->pmssCreateSymlinkOrSkip($this->pmssRepoPath('etc/skel/www/storageHealthRaidDisplay.php'), $helper);
            }
            $result = $this->pmssRunRepoPhpScriptCommand('scripts/lib/tests/fixtures/storageHealthNoticeContract.php', ['bridge', $script]);
            $this->assertSame(0, $result['rc']);
            $this->assertSame('Storage RAID display helper unavailable', $result['output']);
        }
    }
}
