<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/trackerCleaner.php';

final class TrackerCleanerChangeLogSafetyTest extends TestCase
{
    public function testChangeLogAppendWritesInsideExplicitSafeHomeRoot(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-tracker-cleaner-home-');
        $home = $homeRoot.'/testuser';
        $this->assertTrue(@mkdir($home, 0750), 'Expected fixture user home to exist');

        [$log, $output] = $this->pmssCaptureStdout(static function () use ($homeRoot): string {
            return pmssTrackerCleanerAppendUserChangeLog('testuser', ['abc123' => 'Public Torrent'], $homeRoot);
        });

        $this->assertSame('', $output);
        $this->assertStringContainsString('Changed Public Torrent (abc123)', $log);
        $this->assertSame($log, (string) file_get_contents($home.'/.trackerCleaner.log'));
    }

    public function testChangeLogAppendRejectsInvalidUsernameBeforePathUse(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-tracker-cleaner-home-');

        [$log, $output] = $this->pmssCaptureStdout(static function () use ($homeRoot): string {
            return pmssTrackerCleanerAppendUserChangeLog('bad-user', ['abc123' => 'Public Torrent'], $homeRoot);
        });

        $this->assertStringContainsString('Changed Public Torrent (abc123)', $log);
        $this->assertStringContainsString('ERR: Refusing to write tracker change log', $output);
        $this->assertFalse(file_exists($homeRoot.'/bad-user'));
    }

    public function testChangeLogAppendRejectsUnsafeLogTarget(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-tracker-cleaner-home-');
        $home = $homeRoot.'/testuser';
        $outside = $this->pmssMakeTempFile('pmss-tracker-cleaner-outside-');
        $this->assertTrue(@mkdir($home, 0750), 'Expected fixture user home to exist');
        $this->assertTrue(@symlink($outside, $home.'/.trackerCleaner.log'), 'Expected symlink fixture');

        [$log, $output] = $this->pmssCaptureStdout(static function () use ($homeRoot): string {
            return pmssTrackerCleanerAppendUserChangeLog('testuser', ['abc123' => 'Public Torrent'], $homeRoot);
        });

        $this->assertStringContainsString('Changed Public Torrent (abc123)', $log);
        $this->assertStringContainsString('SKIP: refusing to write log; path is symlink', $output);
        $this->assertSame('', (string) file_get_contents($outside));
    }

    public function testChangeLogAppendWarnsWhenHomeIsMissing(): void
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-tracker-cleaner-home-');

        [$log, $output] = $this->pmssCaptureStdout(static function () use ($homeRoot): string {
            return pmssTrackerCleanerAppendUserChangeLog('testuser', ['abc123' => 'Public Torrent'], $homeRoot);
        });

        $this->assertStringContainsString('Changed Public Torrent (abc123)', $log);
        $this->assertStringContainsString('WARN: User change log path is unsafe or missing', $output);
        $this->assertFalse(file_exists($homeRoot.'/testuser/.trackerCleaner.log'));
    }
}
