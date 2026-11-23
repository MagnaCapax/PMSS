<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** A torrent name that no encoder pass can repair must not turn ruTorrent's JSON reply into an empty body. */
class RutorrentSafeJsonEncodeTest extends TestCase
{
    // U+1F600 written as a UTF-16 surrogate pair of two 3-byte sequences (CESU-8), between plain letters.
    private const CESU8_NAME = "x\xed\xa0\xbd\xed\xb8\x80y";
    private const HASH = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testListReplyWithACesu8NameIsParseableJson(): void
    {
        $json = $this->encode([['t' => [self::HASH => [self::CESU8_NAME, 1]]]])[0];
        $this->assertParses($json, 'list');
        $this->assertSame(1, json_decode($json, true)['t'][self::HASH][1]);
    }

    public function testHistoryReplyWithACesu8NameIsParseableJson(): void
    {
        $this->assertParses($this->encode([['data' => [['name' => self::CESU8_NAME]]]])[0], 'history');
    }

    public function testBareCesu8StringKeepsItsSurroundingLetters(): void
    {
        $text = json_decode($this->encode([self::CESU8_NAME])[0], true);
        $this->assertSame('x', substr($text, 0, 1));
        $this->assertSame('y', substr($text, -1));
    }

    public function testLoneSurrogateHalvesAndTruncatedSequencesAreNeverAnEmptyBody(): void
    {
        foreach (["x\xed\xa0\xbdy", "x\xed\xb8\x80y", "x\xed\xa0\xbd", "tail\xe2\x82", "\xf0\x9f\x98"] as $name) {
            $this->assertParses($this->encode([[$name]])[0], bin2hex($name));
        }
    }

    public function testValidUtf8KeepsTodaysBytesIncludingAFourByteEmoji(): void
    {
        $rows = $this->encode([['a' => "caf\xc3\xa9", 'n' => 3], "\xf0\x9f\x98\x80"]);
        $this->assertSame('{"a":"caf\u00e9","n":3}', $rows[0]);
        $this->assertSame('"\ud83d\ude00"', $rows[1]);
    }

    public function testAStrayByteStillBecomesAnUnderscoreNotAReplacementCharacter(): void
    {
        $this->assertSame('"a_b"', $this->encode(["a\xffb"])[0]);
    }

    public function testExistingAccountsReceiveTheFallback(): void
    {
        [$expected, $legacy, $patched] = $this->patchTriple();
        $target = $this->accountUtil(str_replace($patched, $legacy, $expected), 'receives');
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => dirname($target, 4)]);
        $this->assertSame($expected, file_get_contents($target));
    }

    public function testASecondPassLeavesTheFileAndItsInodeAlone(): void
    {
        [$expected, $legacy, $patched] = $this->patchTriple();
        $target = $this->accountUtil(str_replace($patched, $legacy, $expected), 'repeat');
        $home = dirname($target, 4);
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        clearstatcache(true, $target);
        $inode = stat($target)['ino'];
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        clearstatcache(true, $target);
        $this->assertSame($expected, file_get_contents($target));
        $this->assertSame($inode, stat($target)['ino']);
    }

    public function testAnAccountWhoseUtilDiffersIsLeftUntouched(): void
    {
        $custom = "<?php\n// this account's own copy\nfunction safe_json_encode(\$v) { return json_encode(\$v); }\n";
        $target = $this->accountUtil($custom, 'custom');
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => dirname($target, 4)]);
        $this->assertSame($custom, file_get_contents($target));
    }

    public function testASymlinkedUtilIsNotWrittenThrough(): void
    {
        [$expected, $legacy, $patched] = $this->patchTriple();
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-json-', 'dummy', 'www/rutorrent/php');
        $outside = $this->pmssWriteFile($this->pmssMakeTempPhpPath('pmss-rutorrent-json-', 'outside'), str_replace($patched, $legacy, $expected));
        $this->assertTrue(@symlink($outside, $home.'/www/rutorrent/php/util.php'));
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertSame(str_replace($patched, $legacy, $expected), file_get_contents($outside));
    }

    /** @return array{0:string,1:string,2:string} skeleton util.php, the edit's legacy text, its patched text */
    private function patchTriple(): array
    {
        $expected = $this->pmssReadRepoFile('etc/skel/www/rutorrent/php/util.php');
        [$path, $legacy, $patched] = \pmssUserRutorrentJsonEncodePatches()[0];
        $this->assertSame('php/util.php', $path);
        $this->assertSame(1, substr_count($expected, $patched), 'skeleton carries the patched text once');
        return [$expected, $legacy, $patched];
    }

    /** Write one account's www/rutorrent/php/util.php; the home is four directories above it. */
    private function accountUtil(string $content, string $user): string
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-json-', $user, 'www/rutorrent/php');
        return $this->pmssWriteRelativeFile($home, 'www/rutorrent/php/util.php', $content);
    }

    private function assertParses(string $json, string $what): void
    {
        $this->assertTrue($json !== '', $what.' must not be an empty body');
        $this->assertTrue(json_decode($json, true) !== null, $what.' must parse');
    }

    /**
     * Run the skeleton's own mix2utf, utf8ize and safe_json_encode (the util.php include chain needs a live tree).
     *
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function encode(array $values): array
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/rutorrent/php/util.php');
        $start = strpos($src, 'function mix2utf(');
        $end = strpos($src, 'function sortArrayTime(');
        $this->assertTrue($start !== false && $end !== false && $start < $end, 'util.php helper block moved');
        $script = 'eval('.var_export(substr($src, $start, $end - $start), true).');'
            .'$out=[];foreach(unserialize(base64_decode("'.base64_encode(serialize($values)).'")) as $k=>$v){$out[$k]=(string)safe_json_encode($v);}'
            .'echo json_encode($out);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
