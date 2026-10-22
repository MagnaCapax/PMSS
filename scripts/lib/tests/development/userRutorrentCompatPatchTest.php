<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserRutorrentCompatPatchTest extends TestCase
{
    public function testCompatibilityPatchesLegacyTargets(): void
    {
        foreach ($this->compatPatchCases() as $case) {
            $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', $case['dir']);
            $path = $this->pmssWriteRelativeFile($home, $case['path'], $case['legacy']);

            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString($case['expected'], $content);
            $this->pmssAssertStringNotContainsString($case['unexpected'], $content);
        }
    }

    public function testCompatibilityAddsIntervalGuardToPreviouslyPatchedSettings(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "prefix\n\t\t\$tm = getdate();\n\t\t\$startAt = mktime(\$tm[\"hours\"],\n\t\t\t((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n");

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("\$interval = (int)\$interval;\n\t\tif(\$interval<1)\n\t\t\t\$interval = 30;", $content);
        $this->assertEquals(1, substr_count($content, '$interval = (int)$interval;'));
        $this->assertStringContainsString('((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),', $content);
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
        foreach ([
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
            [
                'dir' => 'www/rutorrent/plugins/throttle',
                'path' => 'www/rutorrent/plugins/throttle/throttle.php',
                'content' => "new rXMLRPCCommand( \"set_upload_rate\", 1048576 )\nnew rXMLRPCCommand( \"set_download_rate\", 1048576 )\n",
            ],
        ] as $case) {
            $this->assertCompatibilityContentUntouched($case, $case['content']);
        }
    }

    public function testCompatibilityPatchesAllKnownTargetsInSinglePass(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/php');
        $settingsPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/settings.php', "\t\t\$tm = getdate();\n\t\t\$startAt = mktime(\$tm[\"hours\"],\n\t\t\t((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\n");
        $snoopyPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/Snoopy.class.inc', "\tvar \$accept\t\t\t=\t\"image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, */*\";\n");
        $rssPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/rss/action.php', "ob_flush();\n");
        $hddquotaPath = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/hddquota/action.php', "return \$field;\n");
        $throttlePath = $this->pmssWriteRelativeFile(
            $home,
            'www/rutorrent/plugins/throttle/throttle.php',
            "new rXMLRPCCommand( \"set_upload_rate\", MAX_SPEED )\nnew rXMLRPCCommand( \"set_download_rate\", MAX_SPEED )\n"
        );

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);

        $this->assertStringContainsString("\$interval = (int)\$interval;\n\t\tif(\$interval<1)\n\t\t\t\$interval = 30;", (string) file_get_contents($settingsPath));
        $this->assertStringContainsString('((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),', (string) file_get_contents($settingsPath));
        $this->assertStringContainsString('text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', (string) file_get_contents($snoopyPath));
        $this->pmssAssertStringNotContainsString('image/x-xbitmap', (string) file_get_contents($snoopyPath));
        $this->assertStringContainsString('@ob_flush();', (string) file_get_contents($rssPath));
        $this->assertStringContainsString('return (int) $field;', (string) file_get_contents($hddquotaPath));
        $throttleContent = (string) file_get_contents($throttlePath);
        $this->assertStringContainsString('new rXMLRPCCommand( "set_upload_rate", 0 )', $throttleContent);
        $this->assertStringContainsString('new rXMLRPCCommand( "set_download_rate", 0 )', $throttleContent);
        $this->pmssAssertStringNotContainsString('MAX_SPEED', $throttleContent);
    }

    public function testSkeletonHddquotaActionReturnsInt(): void
    {
        $path = $this->pmssRepoPath('etc/skel/www/rutorrent/plugins/hddquota/action.php');
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('return (int) $field;', $content);
    }

    public function testSkeletonSnoopyAcceptDefaultIsModern(): void
    {
        $path = $this->pmssRepoPath('etc/skel/www/rutorrent/php/Snoopy.class.inc');
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('var $accept			=	"text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8";', $content);
        $this->pmssAssertStringNotContainsString('image/x-xbitmap', $content);
    }

    public function testCompatibilityRebuildsSkeletonFilesAndIsIdempotent(): void
    {
        $cases = $this->compatPatchCases();
        foreach (['php/settings.php', 'plugins/httprpc/action.php', 'plugins/erasedata/init.php'] as $relative) {
            $path = 'www/rutorrent/'.$relative;
            $expected = $this->pmssReadRepoFile('etc/skel/'.$path);
            $legacy = $expected;
            // Reverse all applicable patches, including the pre-existing interval fixes.
            foreach (array_reverse($cases) as $case) {
                if ($case['path'] !== $path) continue;
                $patched = substr($case['patched'], strlen("prefix\n"), -strlen("\nsuffix\n"));
                $original = substr($case['legacy'], strlen("prefix\n"), -strlen("\nsuffix\n"));
                $this->assertSame(1, substr_count($legacy, $patched), $relative);
                $legacy = str_replace($patched, $original, $legacy);
            }
            $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', dirname($path));
            $target = $this->pmssWriteRelativeFile($home, $path, $legacy);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertSame($expected, file_get_contents($target), $relative);
            $metadata = stat($target);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            clearstatcache(true, $target);
            $this->assertSame($expected, file_get_contents($target), $relative.' second pass');
            $this->assertSame($metadata['ino'], stat($target)['ino'], $relative.' must not be rewritten');
        }
    }

    public function testRatioGroupCommandsPreserveLegacyArgumentsAndAdaptModernSetters(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-rutorrent-ratio-');
        // Capture this helper's arguments before XMLRPC's separate legacy alias handling.
        $this->pmssWriteRelativeFile($dir, 'xmlrpc.php', <<<'PHP'
<?php
class rXMLRPCCommand {
    public $command;
    public $args;
    public function __construct($command, $args) { $this->command = $command; $this->args = $args; }
}
PHP
        );
        $this->pmssWriteRelativeFile($dir, 'cache.php', '<?php');
        $script = 'set_include_path('.var_export($dir, true).'); require '
            .var_export($this->pmssRepoPath('etc/skel/www/rutorrent/php/settings.php'), true).';'
            . <<<'PHP'
$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
$result = [];
foreach ([0x903, 0x904, 0x908, 0xfff, 0x1000, 0x100f, 0x1010, 0x1019] as $version) {
    $settings->iVersion = $version;
    foreach (['view', 'ratio.min', 'ratio.max', 'ratio.upload', 'enable'] as $name) {
        foreach ([$name, $name.'.set'] as $command) {
            foreach ([12, [12], [], null, ''] as $args) {
                $rpc = $settings->getRatioGroupCommand('rat_1', $command, $args);
                $result[] = [$version, $command, $args, $rpc->command, $rpc->args];
            }
        }
    }
}
echo json_encode($result);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(400, count($rows));
        foreach ($rows as [$version, $command, $args, $name, $actual]) {
            $modern = $version >= 0x1000;
            $prefix = !$modern && $version >= 0x904 && strpos($command, 'enable') !== 0 ? 'group2.' : 'group.';
            $expected = $args;
            if ($modern && substr($command, -4) === '.set') {
                $expected = is_array($args) ? $args : [$args];
                array_unshift($expected, '');
            }
            $this->assertSame($prefix.'rat_1.'.$command, $name);
            $this->assertSame($expected, $actual);
        }
    }

    public function testRpcCompatibilityAnchorsAreUniqueAndDoNotOverlap(): void
    {
        $byPath = [];
        foreach (\pmssUserRutorrentRpcCompatibilityPatches() as [$path, $legacy, $patched]) {
            $source = $this->pmssReadRepoFile('etc/skel/www/rutorrent/'.$path);
            $this->assertSame(1, substr_count($source, $patched), $path);
            $byPath[$path][] = [$legacy, $patched];
        }
        foreach ($byPath as $path => $replacements) {
            $source = $this->pmssReadRepoFile('etc/skel/www/rutorrent/'.$path);
            foreach (array_reverse($replacements) as [$legacy, $patched]) {
                $source = str_replace($patched, $legacy, $source);
            }
            $end = 0;
            $ranges = [];
            foreach ($replacements as [$legacy]) {
                $this->assertSame(1, substr_count($source, $legacy), $path);
                $start = strpos($source, $legacy);
                $ranges[] = [$start, $start + strlen($legacy)];
            }
            sort($ranges);
            foreach ($ranges as [$start, $stop]) {
                $this->assertTrue($start >= $end, $path.' overlapping anchors');
                $end = $stop;
            }
        }
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
        $cases = [
            [
                'dir' => 'www/rutorrent/php',
                'path' => 'www/rutorrent/php/settings.php',
                'legacy' => "prefix\n\t\t\$tm = getdate();\n\t\t\$startAt = mktime(\$tm[\"hours\"],\n\t\t\t((integer)(\$tm[\"minutes\"]/\$interval))*\$interval+\$interval,\nsuffix\n",
                'patched' => "prefix\n\t\t\$tm = getdate();\n\t\t\$interval = (int)\$interval;\n\t\tif(\$interval<1)\n\t\t\t\$interval = 30;\n\t\t\$startAt = mktime(\$tm[\"hours\"],\n\t\t\t((integer)(\$tm[\"minutes\"]/((int)\$interval)))*((int)\$interval)+((int)\$interval),\nsuffix\n",
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
                'dir' => 'www/rutorrent/php',
                'path' => 'www/rutorrent/php/Snoopy.class.inc',
                'legacy' => "prefix\n\tvar \$accept\t\t\t=\t\"image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, */*\";\nsuffix\n",
                'patched' => "prefix\n\tvar \$accept\t\t\t=\t\"text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\";\nsuffix\n",
                'expected' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'unexpected' => 'image/x-xbitmap',
            ],
            [
                'dir' => 'www/rutorrent/plugins/hddquota',
                'path' => 'www/rutorrent/plugins/hddquota/action.php',
                'legacy' => "prefix\n        return \$field;\nsuffix\n",
                'patched' => "prefix\n        return (int) \$field;\nsuffix\n",
                'expected' => 'return (int) $field;',
                'unexpected' => 'return $field;',
            ],
            [
                'dir' => 'www/rutorrent/plugins/throttle',
                'path' => 'www/rutorrent/plugins/throttle/throttle.php',
                'legacy' => "prefix\nnew rXMLRPCCommand( \"set_upload_rate\", MAX_SPEED )\nnew rXMLRPCCommand( \"set_download_rate\", MAX_SPEED )\nsuffix\n",
                'patched' => "prefix\nnew rXMLRPCCommand( \"set_upload_rate\", 0 )\nnew rXMLRPCCommand( \"set_download_rate\", 0 )\nsuffix\n",
                'expected' => "new rXMLRPCCommand( \"set_upload_rate\", 0 )\nnew rXMLRPCCommand( \"set_download_rate\", 0 )",
                'unexpected' => "new rXMLRPCCommand( \"set_upload_rate\", MAX_SPEED )\nnew rXMLRPCCommand( \"set_download_rate\", MAX_SPEED )",
            ],
        ];
        // One fixture per new delivery entry extends every existing safety scenario.
        foreach (\pmssUserRutorrentRpcCompatibilityPatches() as [$path, $legacy, $patched]) {
            $cases[] = [
                'dir' => 'www/rutorrent/'.dirname($path),
                'path' => 'www/rutorrent/'.$path,
                'legacy' => "prefix\n".$legacy."\nsuffix\n",
                'patched' => "prefix\n".$patched."\nsuffix\n",
                'expected' => $patched,
                'unexpected' => "prefix\n".$legacy."\nsuffix\n",
            ];
        }
        return $cases;
    }

}

}
