<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users/filesystem.php';

class UserPanelIndexFrameDataCompatRefreshTest extends TestCase
{
    private $homeRoot;
    private $home;
    private $user = 'paneluser';
    private $skelIndex;

    protected function setUp(): void
    {
        $skelBase = \pmssSkeletonBase();
        if ($skelBase === '/etc/skel') {
            throw new SkipTest('PMSS_SKEL_DIR not set to a temp path');
        }

        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-panel-index-');
        $this->home = $this->homeRoot.'/'.$this->user;
        $this->skelIndex = $skelBase.'/www/index.php';

        @mkdir($this->home.'/www', 0755, true);
        $this->pmssWriteFile($this->skelIndex, $this->fixedPanelIndexSource());
    }

    protected function tearDown(): void
    {
        if (is_file($this->skelIndex)) {
            @unlink($this->skelIndex);
        }
    }

    public function testDetectorMatchesLegacyFrameDataMergeWithoutInitializer(): void
    {
        $this->assertTrue(\pmssUserPanelIndexNeedsFrameDataCompatRefresh($this->legacyPanelIndexSource()));
    }

    public function testDetectorIgnoresInitializedPanelCopy(): void
    {
        $this->assertFalse(\pmssUserPanelIndexNeedsFrameDataCompatRefresh($this->customInitializedPanelSource()));
    }

    public function testRefreshReplacesLegacyPanelIndexFromSkeleton(): void
    {
        $this->writeUserPanelIndex($this->legacyPanelIndexSource());

        \pmssUserRefreshPanelIndexForFrameDataCompat($this->context());

        $this->assertEquals($this->fixedPanelIndexSource(), file_get_contents($this->home.'/www/index.php'));
    }

    public function testSkeletonApplyFlowRefreshesLegacyPanelIndexFromSkeleton(): void
    {
        $this->writeUserPanelIndex($this->legacyPanelIndexSource());

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertEquals($this->fixedPanelIndexSource(), file_get_contents($this->home.'/www/index.php'));
    }

    public function testTargetedRefreshLeavesCustomInitializedPanelCopyAloneInIsolation(): void
    {
        $custom = $this->customInitializedPanelSource();
        $this->writeUserPanelIndex($custom);

        // The full skeleton apply flow may still refresh this file via updateUserFile().
        \pmssUserRefreshPanelIndexForFrameDataCompat($this->context());

        $this->assertEquals($custom, file_get_contents($this->home.'/www/index.php'));
    }

    public function testRefreshSkipsSymlinkTarget(): void
    {
        $outside = $this->pmssMakeTempFile('pmss-panel-outside-');
        file_put_contents($outside, 'outside');
        @unlink($this->home.'/www/index.php');
        $this->pmssCreateSymlinkOrSkip($outside, $this->home.'/www/index.php');

        \pmssUserRefreshPanelIndexForFrameDataCompat($this->context());

        $this->assertTrue(is_link($this->home.'/www/index.php'));
        $this->assertEquals('outside', file_get_contents($outside));
    }

    public function testRefreshSkipsUnrelatedFileMissingFrameDataMerge(): void
    {
        $custom = "<?php\n// User-owned custom panel.\necho 'ok';\n";
        $this->writeUserPanelIndex($custom);

        \pmssUserRefreshPanelIndexForFrameDataCompat($this->context());

        $this->assertEquals($custom, file_get_contents($this->home.'/www/index.php'));
    }

    private function context(): array
    {
        return [
            'user' => $this->user,
            'home' => $this->home,
        ];
    }

    private function writeUserPanelIndex(string $content): void
    {
        file_put_contents($this->home.'/www/index.php', $content);
    }

    private function legacyPanelIndexSource(): string
    {
        return <<<'PHP'
<?php
$frames = array();
if (file_exists('../.customFrames')) {
    $frameArray = array('custom', 'Custom');
    $frameData[$frameArray[0]] = array('title' => $frameArray[1]);
}
$frames = array_merge($frames, $frameData);
PHP;
    }

    private function customInitializedPanelSource(): string
    {
        return <<<'PHP'
<?php
$frames = array();
$frameData = array();
if (file_exists('../.customFrames')) {
    $frameData['custom'] = array('title' => 'Custom');
}
$frames = array_merge($frames, $frameData);
echo 'custom';
PHP;
    }

    private function fixedPanelIndexSource(): string
    {
        return <<<'PHP'
<?php
function pmssFrameOpensInNewWindow(array $frame)
{
    return isset($frame['target']) && $frame['target'] === '_blank';
}

$frames = array();
$frameData = array();
if (file_exists('../.customFrames')) {
    $frameData['custom'] = array('title' => 'Custom');
}
$frames = array_merge($frames, $frameData);
PHP;
    }
}
