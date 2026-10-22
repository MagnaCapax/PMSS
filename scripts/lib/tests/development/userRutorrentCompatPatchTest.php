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
        // Alternative legacy anchors share a final block; reverse each block once.
        $seen = [];
        $cases = array_values(array_filter($cases, static function ($case) use (&$seen) {
            $key = $case['path']."\0".$case['patched'];
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
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

    public function testRoundOneAccountsConvergeToSkeletonWithoutRewritingOnSecondPass(): void
    {
        $groups = [];
        foreach (\pmssUserRutorrentRpcCompatibilityPatches() as [$path, $legacy, $patched]) {
            $groups[$path][$patched][] = $legacy;
        }
        foreach (['php/settings.php', 'plugins/httprpc/action.php'] as $relative) {
            $expected = $this->pmssReadRepoFile('etc/skel/www/rutorrent/'.$relative);
            $roundOne = $expected;
            foreach (array_reverse($groups[$relative], true) as $patched => $alternatives) {
                // Only the alias/function alternatives and fixed setters changed in round 2.
                if (count($alternatives) === 1 && strpos($patched, '$req->success($trusted') === false) continue;
                $this->assertSame(1, substr_count($roundOne, $patched), $relative);
                $roundOne = str_replace($patched, end($alternatives), $roundOne);
            }
            $this->assertTrue($roundOne !== $expected, $relative.' needs round-2 changes');
            $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/'.dirname($relative));
            $target = $this->pmssWriteRelativeFile($home, 'www/rutorrent/'.$relative, $roundOne);
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            $this->assertSame($expected, file_get_contents($target), $relative);
            $inode = stat($target)['ino'];
            \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
            clearstatcache(true, $target);
            $this->assertSame($expected, file_get_contents($target), $relative.' second pass');
            $this->assertSame($inode, stat($target)['ino']);
        }
    }

    public function testRutorrentBackupOperationKeepsItsOriginalLine(): void
    {
        $lines = explode("\n", $this->pmssReadRepoFile('scripts/lib/update/users/rutorrent.php'));
        $this->assertStringContainsString("'Backing up existing ruTorrent'", $lines[181]);
    }

    public function testObtainUsesVersionedDhtAndPortAliases(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-rutorrent-aliases-');
        $this->pmssWriteRelativeFile($dir, 'xmlrpc.php', <<<'PHP'
<?php
class rXMLRPCCommand {
    public $command;
    public function __construct($command, $args = null) { $this->command = $command; }
}
class rXMLRPCRequest {
    public $val = [];
    public $important;
    private $command;
    public function __construct($command) { $this->command = $command->command; }
    public function run() {
        $this->val = [$GLOBALS['version']];
        return $this->command === 'system.client_version';
    }
    public function success() { $this->val = [13]; return true; }
}
PHP
        );
        $this->pmssWriteRelativeFile($dir, 'cache.php', '<?php');
        $phpDir = $this->pmssRepoPath('etc/skel/www/rutorrent/php');
        foreach (['0.9.8', '0.15.255', '0.16.0', '0.16.15', '0.16.16', '0.16.17', '0.16.18', '0.16.25'] as $version) {
            // A separate process respects methods-0.9.4.php's require_once lifecycle.
            $script = '$version = '.var_export($version, true).'; set_include_path('
                .var_export($dir.PATH_SEPARATOR.$phpDir, true).'); require '
                .var_export($phpDir.'/settings.php', true).';'
                .'$settings = (new ReflectionClass("rTorrentSettings"))->newInstanceWithoutConstructor();'
                .'$settings->obtain(); echo json_encode([$settings->iVersion, $settings->aliases]);';
            [$numeric, $aliases] = $this->pmssRunInlinePhpJson($script);
            $this->assertSame($numeric >= 0x1000 ? ['name' => 'dht.mode.set', 'prm' => 1] : null, $aliases['dht'] ?? null, $version);
            foreach (['range', 'random'] as $name) {
                $prefix = $numeric >= 0x1012 ? 'network.listen.port.' : 'network.port_';
                $this->assertSame(['name' => $prefix.$name, 'prm' => 0], $aliases['get_port_'.$name], $version);
                $this->assertSame(['name' => $prefix.$name.'.set', 'prm' => 1], $aliases['set_port_'.$name], $version);
            }
            foreach (['get_port_open' => 0, 'set_port_open' => 1, 'port_open' => 0] as $name => $parameters) {
                if ($numeric >= 0x1012) $this->assertSame(['name' => 'cat', 'prm' => $parameters], $aliases[$name], $version);
                else $this->assertTrue(!isset($aliases[$name]) || $aliases[$name]['name'] !== 'cat', $version);
            }
        }
    }

    public function testSetpropsTrustRequiresEveryNameToBeFromBundledUi(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/rutorrent/plugins/httprpc/action.php');
        preg_match('/if\(\$req->success\((\$trusted && [^\n]+)\)\)/', $source, $match);
        $this->assertTrue(isset($match[1]), 'setprops trust decision must be present');
        $script = '$decision = '.var_export($match[1], true).';'. <<<'PHP'
$rows = [];
$allowed = ['superseed', 'ulslots', 'pex', 'peers_min', 'peers_max', 'tracker_numwant'];
$cases = array_merge([[], $allowed, ['pex', 'pex']], array_map(function ($s) { return [$s]; }, $allowed));
foreach (['', 'custom1', 'execute', 'peers_max.set', 'peers_max=', 'PEX', 'peers_min ', '<methodName>execute</methodName>'] as $name) {
    $cases[] = [$name];
    $cases[] = ['pex', $name];
    $cases[] = [$name, 'pex'];
}
foreach ([0x908, 0xfff, 0x1000, 0x1019] as $version) {
    $trusted = $version >= 0x1000;
    foreach ($cases as $ss) $rows[] = [$version, $ss, eval('return '.$decision.';')];
}
echo json_encode($rows);
PHP;
        foreach ($this->pmssRunInlinePhpJson($script) as [$version, $properties, $actual]) {
            $expected = $version >= 0x1000;
            foreach ($properties as $property) {
                if (!in_array($property, ['superseed', 'ulslots', 'pex', 'peers_min', 'peers_max', 'tracker_numwant'], true)) $expected = false;
            }
            $this->assertSame($expected, $actual, json_encode([$version, $properties]));
        }
    }

    public function testOnlyFixedHttprpcModesUseTrustedCalls(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/rutorrent/plugins/httprpc/action.php');
        $this->assertSame(1, substr_count($source, '$trusted = rTorrentSettings::get()->iVersion >= 0x1000;'));
        foreach (['trkstate', 'setprio', 'dsetprio', 'setlabel', 'snub', 'ban', 'kick', 'add_peer'] as $mode) {
            $start = strpos($source, 'case "'.$mode.'":');
            $block = substr($source, $start, strpos($source, "\tcase ", $start+1)-$start);
            $this->assertStringContainsString('$req->success($trusted)', $block, $mode);
        }
        $start = strpos($source, 'case "stg":');
        $stg = substr($source, $start, strpos($source, "\tcase ", $start+1)-$start);
        $this->assertStringContainsString('$req->success($trusted)', $stg);
        $this->assertStringContainsString('$addReq->success(false)', $stg);
        $this->assertStringContainsString('$addReq = ($trusted && count($add)) ? new rXMLRPCRequest() : $req;', $stg);
        foreach (['list', 'ttl', 'prp', 'trkall', 'setsettings', 'setul', 'setdl', 'getchunks'] as $mode) {
            $start = strpos($source, 'case "'.$mode.'":');
            $end = strpos($source, "\tcase ", $start+1);
            if ($end === false) $end = strpos($source, "\tdefault:", $start+1);
            $block = substr($source, $start, $end-$start);
            $this->assertStringContainsString('$req->success(false)', $block, $mode);
            $this->pmssAssertStringNotContainsString('$req->success($trusted', $block);
        }
        $this->assertStringContainsString('rXMLRPCRequest::send($HTTP_RAW_POST_DATA,false)', $source);
        $this->assertStringContainsString('$req->success(false)', substr($source, strpos($source, 'function makeMulticall'), strpos($source, 'function makeSimpleCall')-strpos($source, 'function makeMulticall')));
    }

    public function testRpcCompatibilityAnchorsAreUniqueAndDoNotOverlap(): void
    {
        $byPath = [];
        foreach (\pmssUserRutorrentRpcCompatibilityPatches() as [$path, $legacy, $patched]) {
            $source = $this->pmssReadRepoFile('etc/skel/www/rutorrent/'.$path);
            $this->assertSame(1, substr_count($source, $patched), $path);
            // Check both supported input generations independently, including alternatives.
            $byPath[$path][$patched][] = $legacy;
        }
        foreach ($byPath as $path => $groups) {
            foreach ([0, 1] as $variant) {
                $replacements = [];
                foreach ($groups as $patched => $alternatives) {
                    $replacements[] = [$alternatives[min($variant, count($alternatives)-1)], $patched];
                }
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
