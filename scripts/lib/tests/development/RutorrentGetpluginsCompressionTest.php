<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * The per-user web server spills an answer over 64 KiB into a temp file in the account home, which
 * fails at disk quota and cuts the answer. The plugin list answer must therefore be compressed in
 * memory, and no other ruTorrent endpoint changes behaviour.
 */
class RutorrentGetpluginsCompressionTest extends TestCase
{
    /** Both the generated per-user config template and the skeleton copy carry the switch. */
    private const CONFIG_FILES = ['etc/seedbox/config/template.rutorrent.config', 'etc/skel/www/rutorrent/conf/config.php'];

    /** Report zlib.output_compression after a request for $script loaded the config in a child process. */
    private function compressionFor(string $configFile, string $script): string
    {
        $code = '$_SERVER["SCRIPT_FILENAME"] = '.var_export($script, true).'; '
            .'require '.var_export($this->pmssRepoPath($configFile), true).'; '
            .'echo json_encode([(string) ini_get("zlib.output_compression")]);';
        return $this->pmssRunInlinePhpJson($code)[0];
    }

    public function testGetpluginsAnswerIsCompressedInMemory(): void
    {
        $this->pmssSkipUnlessFunctionExists('gzencode');
        foreach (self::CONFIG_FILES as $file) {
            foreach (['/home/u/www/rutorrent/php/getplugins.php', 'getplugins.php'] as $script) {
                $this->assertSame('1', $this->compressionFor($file, $script), $file.' for '.$script);
            }
        }
    }

    public function testOtherEndpointsKeepTheirContentLengthPath(): void
    {
        $this->pmssSkipUnlessFunctionExists('gzencode');
        foreach (self::CONFIG_FILES as $file) {
            foreach (['/home/u/www/rutorrent/php/getsettings.php', '/home/u/www/rutorrent/php/getplugins.php.bak',
                '/home/u/www/rutorrent/plugins/data/action.php', '', 'xgetplugins.php'] as $script) {
                $this->assertSame('', $this->compressionFor($file, $script), $file.' for '.$script);
            }
        }
    }

    public function testCachedEchoOmitsContentLengthWhenCompressionIsOn(): void
    {
        $this->pmssAssertFileContainsAllStrings($this->pmssRepoPath('etc/skel/www/rutorrent/php/util.php'),
            ['if(!ini_get("zlib.output_compression"))', 'header("Content-Length: ".$len);']);
    }
}
