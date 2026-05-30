<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyTargets(): void
    {
        foreach ($this->compatPatchCases() as $case) {
            $this->assertCompatibilityPatch($case);
        }
    }

    public function testCompatibilityLeavesPatchedTargetsUntouched(): void
    {
        foreach ($this->compatPatchCases() as $case) {
            $this->assertCompatibilityContentUntouched($case, $case['patched']);
        }
    }

    public function testCompatibilitySkipsMissingTargets(): void
    {
        foreach ($this->compatPatchCases() as $case) {
            $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', $case['dir']);

            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertTrue(!file_exists($home.'/'.$case['path']));
        }
    }

    public function testCompatibilitySkipsSymlinkedTargets(): void
    {
        foreach ($this->compatPatchCases() as $case) {
            $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', $case['dir']);
            $target = $this->pmssWriteFile($this->pmssMakeTempPhpPath('pmss-user-rutorrent-', 'symlink-target'), $case['legacy']);
            @symlink($target, $home.'/'.$case['path']);

            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertEquals($case['legacy'], (string) file_get_contents($target));
        }
    }

    public function testCompatibilityLeavesNonMatchingContentUntouched(): void
    {
        foreach ($this->compatUntouchedCases() as $case) {
            $this->assertCompatibilityContentUntouched($case, $case['content']);
        }
    }

    public function testCompatibilityPatchesAllKnownTargetsInSinglePass(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $settingsPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        $rssPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/rss/action.php', "ob_flush();\n");
        $hddquotaPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/hddquota/action.php', "return \$field;\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);

        $this->assertStringContainsString('((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),', (string) file_get_contents($settingsPath));
        $this->assertStringContainsString('@ob_flush();', (string) file_get_contents($rssPath));
        $this->assertStringContainsString('return (int) $field;', (string) file_get_contents($hddquotaPath));
    }

    public function testSkeletonHddquotaActionReturnsInt(): void
    {
        $path = $this->pmssRepoPath('etc/skel/www/rutorrent/plugins/hddquota/action.php');
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('return (int) $field;', $content);
    }

    private function assertCompatibilityPatch(array $case): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', $case['dir']);
        $path = $this->pmssWriteRelativeFile($home, $case['path'], $case['legacy']);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString($case['expected'], $content);
        $this->pmssAssertStringNotContainsString($case['unexpected'], $content);
    }

    private function assertCompatibilityContentUntouched(array $case, string $content): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', $case['dir']);
        $path = $this->pmssWriteRelativeFile($home, $case['path'], $content);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertEquals($content, (string) file_get_contents($path));
    }

    private function compatPatchCases(): array
    {
        return [
            [
                'dir' => 'www/rutorrent/php',
                'path' => 'www/rutorrent/php/settings.php',
                'legacy' => "prefix\n((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n",
                'patched' => "prefix\n((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n",
                'expected' => '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),',
                'unexpected' => '((integer)($tm["minutes"]/$interval))*$interval+$interval,',
            ],
            [
                'dir' => 'www/rutorrent/plugins/rss',
                'path' => 'www/rutorrent/plugins/rss/action.php',
                'legacy' => "before\nob_flush();\nafter\n",
                'patched' => "before\n@ob_flush();\nafter\n",
                'expected' => '@ob_flush();',
                'unexpected' => "\nob_flush();\n",
            ],
            [
                'dir' => 'www/rutorrent/plugins/hddquota',
                'path' => 'www/rutorrent/plugins/hddquota/action.php',
                'legacy' => "prefix\n        return \$field;\nsuffix\n",
                'patched' => "prefix\n        return (int) \$field;\nsuffix\n",
                'expected' => 'return (int) $field;',
                'unexpected' => 'return $field;',
            ],
        ];
    }

    private function compatUntouchedCases(): array
    {
        return [
            [
                'dir' => 'www/rutorrent/php',
                'path' => 'www/rutorrent/php/settings.php',
                'content' => "prefix\n\$interval = \$interval * 60;\nsuffix\n",
            ],
            [
                'dir' => 'www/rutorrent/plugins/rss',
                'path' => 'www/rutorrent/plugins/rss/action.php',
                'content' => "before\nflush();\nafter\n",
            ],
        ];
    }

}

}
