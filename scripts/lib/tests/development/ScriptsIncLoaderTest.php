<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the customer loader through the same home-level shim used by rTorrent. */
final class ScriptsIncLoaderTest extends TestCase
{
    private const PARTS = array('Format', 'Files', 'Frontend', 'Bonus', 'Cgroup',
        'Backup', 'RestoreTar', 'Restore', 'AppProcess', 'AppsHtml', 'AppsStatus', 'ActionJs');

    private function makeHome(array $parts): string
    {
        $home = $this->pmssMakeTempDir('pmss-scriptsinc-loader-');
        $this->pmssEnsureDir($home.'/www');
        foreach (array('.scriptsInc.php', 'www/scriptsInc.php') as $file) {
            $this->pmssWriteFile($home.'/'.$file, $this->pmssReadRepoFile('etc/skel/'.$file));
        }
        foreach ($parts as $part) {
            $file = 'scriptsInc'.$part.'.php';
            $this->pmssWriteFile($home.'/www/'.$file, $this->pmssReadRepoFile('etc/skel/www/'.$file));
        }
        return $home;
    }

    public function testRtorrentShimRetainsWriteLogWhenEveryPartIsAbsent(): void
    {
        $home = $this->makeHome(array());
        foreach (array('.rtorrentExecute.php', '.rtorrentRestart.php') as $script) {
            $source = $this->pmssReadRepoFile('etc/skel/'.$script);
            $this->assertStringContainsString("require_once '.scriptsInc.php'", $source);
        }
        $result = $this->pmssRunInlinePhpRequire($home.'/.scriptsInc.php',
            'echo function_exists("writeLog") ? "ok" : "missing";', array(), '2>&1');
        $this->assertSame('ok', trim($result));
    }

    public function testAllDeliveredPartsProvideConsumerFunctions(): void
    {
        $home = $this->makeHome(self::PARTS);
        $source = '<?php require '.var_export($home.'/.scriptsInc.php', true).';'
            .'echo json_encode(get_defined_functions()["user"]);';
        $probe = $this->pmssWriteFile($home.'/probe.php', $source);
        $functions = array_fill_keys(array_map('strtolower', $this->pmssDecodeJsonArray(
            $this->pmssRunInlinePhpRequire($probe, '', array(), '2>&1'))), true);
        $this->assertTrue(isset($functions['writelog']));

        $www = $this->pmssRepoPath('etc/skel/www');
        $partNames = array();
        foreach (self::PARTS as $part) {
            $tokens = token_get_all($this->pmssReadRepoFile('etc/skel/www/scriptsInc'.$part.'.php'));
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
                if (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $partNames[$tokens[$next][1]] = true;
                    $this->assertTrue(isset($functions[strtolower($tokens[$next][1])]), $part.' did not load '.$tokens[$next][1]);
                }
            }
        }
        $consumers = 0;
        foreach (glob($www.'/*.php') as $file) {
            $body = (string) file_get_contents($file);
            if (strpos($body, "require_once __DIR__.'/scriptsInc.php'") === false) continue;
            $consumers++;
            preg_match_all('/\b(pmss[A-Za-z0-9_]+)\s*\(/', $body, $calls);
            foreach (array_unique($calls[1]) as $call) {
                if (isset($partNames[$call])) $this->assertTrue(isset($functions[strtolower($call)]), basename($file).' needs '.$call);
            }
        }
        $this->assertTrue($consumers > 10, 'Expected the customer PHP consumers to be scanned.');
        foreach (array('pmssJsonFileReadAssoc', 'pmssCustomerBonusDisplayStateRead',
            'pmssCustomerCgroupMemoryRead', 'pmssCustomerCgroupCounterPaths',
            'pmssCustomerCgroupCounterRead', 'pmssCustomerTrimmedFileRead',
            'pmssCustomerSerializedArrayFileRead', 'pmssFormatBytes',
            'pmssCustomerUnsignedIntegerFileRead') as $name) {
            $this->assertTrue(isset($functions[strtolower($name)]), 'userShowLimits needs '.$name);
        }
    }

    public function testMissingAndSymlinkedPartsDoNotAbortLoader(): void
    {
        $parts = self::PARTS;
        $parts = array_values(array_diff($parts, array('Bonus', 'Cgroup')));
        $home = $this->makeHome($parts);
        $this->assertTrue(symlink($this->pmssRepoPath('etc/skel/www/scriptsIncCgroup.php'),
            $home.'/www/scriptsIncCgroup.php'));
        $result = $this->pmssRunInlinePhpRequire($home.'/.scriptsInc.php',
            'echo json_encode(array(function_exists("writeLog"), function_exists("pmssFormatBytes"),'
            .'function_exists("pmssCustomerBonusDisplayStateRead"), function_exists("pmssCustomerCgroupMemoryRead")));',
            array(), '2>&1');
        $this->assertSame(array(true, true, false, false), $this->pmssDecodeJsonArray($result));
    }
}
