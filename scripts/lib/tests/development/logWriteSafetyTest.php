<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';

final class LogWriteSafetyTest extends TestCase
{
    public function testLogWritePathIsSafeAcceptsRegularFileTarget(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-dir-').'/events.log';

        $this->assertTrue(\pmssLogWritePathIsSafe($path));
    }

    public function testLogWritePathIsSafeRejectsControlCharacters(): void
    {
        $this->assertFalse(\pmssLogWritePathIsSafe("/tmp/pmss-log\nunsafe.log"));
    }

    public function testJsonLineAppendRejectsSymlinkTarget(): void
    {
        $target = $this->pmssMakeTempFile('pmss-log-target-');
        $link = $this->pmssMakeTempDir('pmss-log-link-dir-').'/events.jsonl';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertFalse(\pmssJsonLineAppend($link, ['event' => 'blocked']));
        $this->assertEquals('', (string) @file_get_contents($target));
    }

    public function testJsonLineAppendRejectsDirectoryTarget(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-log-directory-');

        $this->assertFalse(\pmssJsonLineAppend($directory, ['event' => 'blocked']));
    }

    public function testLogAppendTimestampedLineRejectsSymlinkedParentDirectory(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-log-parent-real-');
        $linkRoot = $this->pmssMakeTempDir('pmss-log-parent-link-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $this->assertFalse(\pmssLogAppendTimestampedLine($linkDir.'/events.log', 'blocked'));
        $this->assertFalse(is_file($targetDir.'/events.log'));
    }

    public function testLogAppendTimestampedLineRejectsNestedSymlinkedAncestorDirectory(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-log-parent-real-');
        mkdir($targetDir.'/nested');
        $linkRoot = $this->pmssMakeTempDir('pmss-log-parent-link-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $this->assertFalse(\pmssLogAppendTimestampedLine($linkDir.'/nested/events.log', 'blocked'));
        $this->assertFalse(is_file($targetDir.'/nested/events.log'));
    }

    public function testLogWritePathIsSafeRejectsTraversalSegments(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-log-traversal-');

        $this->assertFalse(\pmssLogWritePathIsSafe($baseDir.'/../events.log'));
    }

    public function testLogAppendTimestampedLineWritesRegularFileTargets(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-write-dir-').'/events.log';

        $this->assertTrue(\pmssLogAppendTimestampedLine($path, 'ready', '[Y-m-d H:i:s] ', '[INFO] '));
        $this->assertStringContainsString('[INFO] ready', (string) @file_get_contents($path));
    }
}
