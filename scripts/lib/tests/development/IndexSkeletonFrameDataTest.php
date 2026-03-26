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

    public function testUserSkeletonSyncIncludesIndexTemplate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertStringContainsString("'www/index.php',", $source);
    }
}
