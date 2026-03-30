<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class IndexSkeletonFrameDataTest extends TestCase
{
    public function testCustomFrameAccumulatorStartsAsArrayBeforeMerge(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertOrderedStrings(
            [
                '$frameData = array();',
                "if (file_exists('../.customFrames')) {",
                '$frames = array_merge($frames, $frameData);',
            ],
            $source,
            'Missing index.php frame handling fragment: ',
            'index.php custom frame initialization order changed at: '
        );
    }

    public function testWikiFrameUsesNewWindowTargetInsteadOfIframe(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');

        $this->assertOrderedStrings(
            [
                'function pmssFrameOpensInNewWindow(array $frame): bool',
                "'wiki' => array(",
                "'target'   => '_blank',",
            ],
            $source,
            'Missing index.php new-window fragment: ',
            'index.php wiki target order changed at: '
        );

        $this->assertStringContainsAllStrings(
            [
                'target="_blank" rel="noopener noreferrer"',
                'if (pmssFrameOpensInNewWindow($thisFrame)) {',
                "\$styleList[] = '#' . \$thisId;",
            ],
            $source,
            'Missing external-tab handling fragment: '
        );

        $this->pmssAssertStringNotContainsString(
            "<iframe id=\"wikiFrame\"",
            $source,
            'Wiki should no longer be hard-wired into an iframe container.'
        );
    }

    public function testUserSkeletonSyncIncludesIndexTemplate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertStringContainsString("'www/index.php',", $source);
    }
}
