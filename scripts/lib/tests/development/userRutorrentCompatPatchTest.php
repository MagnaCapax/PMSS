<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/rutorrent.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    public function testPatchRewritesLegacyScheduleExpression(): void
    {
        $path = $this->tempPath('rewrite-legacy');
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n");

        $patched = \pmssUserRutorrentScheduleIntervalPatchApply($path);
        $content = (string) file_get_contents($path);

        $this->assertTrue($patched);
        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
        $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/$interval))*$interval+$interval,') === false);

        @unlink($path);
    }

    public function testPatchReturnsTrueWhenAlreadyPatched(): void
    {
        $path = $this->tempPath('already-patched');
        file_put_contents($path, "prefix\n((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n");

        $patched = \pmssUserRutorrentScheduleIntervalPatchApply($path);

        $this->assertTrue($patched);

        @unlink($path);
    }

    public function testPatchReturnsFalseWhenTargetMissing(): void
    {
        $this->assertTrue(!\pmssUserRutorrentScheduleIntervalPatchApply($this->tempPath('missing')));
    }

    public function testPatchReturnsFalseForSymlinkTarget(): void
    {
        $target = $this->tempPath('symlink-target');
        $link = $this->tempPath('symlink-link');
        file_put_contents($target, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        @symlink($target, $link);

        $this->assertTrue(!\pmssUserRutorrentScheduleIntervalPatchApply($link));

        @unlink($link);
        @unlink($target);
    }

    public function testPatchReturnsFalseWhenLegacyExpressionIsMissing(): void
    {
        $path = $this->tempPath('no-legacy-pattern');
        file_put_contents($path, "prefix\n\$interval = \$interval * 60;\nsuffix\n");

        $this->assertTrue(!\pmssUserRutorrentScheduleIntervalPatchApply($path));

        @unlink($path);
    }

    public function testMaintainCompatibilityTargetsUserSettingsPath(): void
    {
        $home = sys_get_temp_dir().'/pmss-rutorrent-home-'.bin2hex(random_bytes(4));
        @mkdir($home.'/www/rutorrent/php', 0755, true);
        $settingsPath = $home.'/www/rutorrent/php/settings.php';
        file_put_contents($settingsPath, "((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");

        try {
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $content = (string) file_get_contents($settingsPath);
            $this->assertTrue(strpos($content, '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),') !== false);
        } finally {
            $this->cleanup($home);
        }
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().'/pmss-user-rutorrent-'.$suffix.'-'.bin2hex(random_bytes(4)).'.php';
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

}
