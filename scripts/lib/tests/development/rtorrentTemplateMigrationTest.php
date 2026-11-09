<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/legacyDirectives.php';

class RtorrentTemplateMigrationTest extends TestCase
{
    public function testNormalizesLegacyTemplateCases(): void
    {
        foreach ([
            'tracker count' => ["tracker_numwant = 42\n", "trackers.numwant.set = 42\n"],
            'UDP trackers' => ["use_udp_trackers = no\n", "trackers.use_udp.set = no\n"],
            'port range and hash' => [
                "port_range = 40000-45000\ncheck_hash = yes\n",
                "network.port_range.set = 40000-45000\npieces.hash.on_completion.set = yes\n",
            ],
            'scheduler aliases' => [
                "schedule = watch_directory,15,1,\"load_start=~/watch/*.torrent\"\nschedule_remove = watch_directory\n",
                "schedule2 = watch_directory,15,1,\"load.start=~/watch/*.torrent\"\nschedule_remove2 = watch_directory\n",
            ],
            'inline aliases in modern scheduler lines' => [
                "schedule2 = rss,0,1800,\"execute=sh,-c,echo ok\"\n"
                    ."schedule2 = watch_directory,15,1,\"load_start_verbose=~/watch/*.torrent\"\n",
                "schedule2 = rss,0,1800,\"execute2=sh,-c,echo ok\"\n"
                    ."schedule2 = watch_directory,15,1,\"load.start_verbose=~/watch/*.torrent\"\n",
            ],
            'load and execute aliases only' => [
                "load_start = ~/watch/example.torrent\nload_start_verbose = ~/watch/verbose.torrent\nexecute = sh,-c,echo ok\nexecute.nothrow = chmod,770,~/.rtorrent.socket\n",
                "load.start = ~/watch/example.torrent\nload.start_verbose = ~/watch/verbose.torrent\nexecute2 = sh,-c,echo ok\nexecute.nothrow = chmod,770,~/.rtorrent.socket\n",
            ],
            'obsolete directives' => [
                "directory = ~/data\numask = 0002\nhash_interval = 300\nhash_max_tries = 2\n",
                "directory = ~/data\n",
            ],
            'duplicate modern directive' => [
                "trackers.use_udp.set = yes\nuse_udp_trackers = yes\n",
                "trackers.use_udp.set = yes\n",
            ],
            'duplicate legacy directive' => [
                "tracker_numwant = 42\ntracker_numwant = 42\n",
                "trackers.numwant.set = 42\n",
            ],
            'unrelated lines and CRLF endings' => [
                "directory = ~/data\r\ntracker_numwant = -1\r\ncustom = keep\r\n",
                "directory = ~/data\r\ntrackers.numwant.set = -1\r\ncustom = keep\r\n",
            ],
        ] as $label => [$input, $expected]) {
            $this->assertEquals($expected, \pmssRtorrentNormalizeLegacyTemplate($input), $label);
        }
    }

    /**
     * The updater should rewrite or remove every directive in the shared catalog.
     */
    public function testNormalizerFollowsSharedLegacyDirectiveCatalog(): void
    {
        $input = '';
        $expected = '';
        foreach (\pmssRtorrentLegacyDirectiveCatalog() as $legacy => $rule) {
            $input .= $legacy." = VALUE\n";
            if ($rule['replacement'] !== null) {
                $expected .= $rule['replacement']." = VALUE\n";
            }
        }

        $this->assertEquals($expected, \pmssRtorrentNormalizeLegacyTemplate($input));
    }

    public function testNormalizerRetainsWhitespaceAliasBoundariesAndDuplicatePolicy(): void
    {
        foreach ([
            ['', ''],
            ["\n\n", "\n\n"],
            ["umask =\nhash_interval=\nhash_max_tries = \n", "umask =\nhash_interval=\n"],
            ["tracker_numwant=\ntracker_numwant = \ntracker_numwant = \n", "tracker_numwant=\ntrackers.numwant.set =  \n"],
            ["trackers.numwant.set = \ntrackers.numwant.set = \n", "trackers.numwant.set = \ntrackers.numwant.set = \n"],
            [" trackers.numwant.set = 42 \ntracker_numwant = 42\n", " trackers.numwant.set = 42 \n"],
            ["tracker_numwant = 42\ntrackers.numwant.set=42\ntrackers.numwant.set=42\n", "trackers.numwant.set = 42\ntrackers.numwant.set=42\ntrackers.numwant.set=42\n"],
            ["# execute=ok xexecute=no .execute=no _execute=no /execute=yes\n", "# execute2=ok xexecute=no .execute=no _execute=no /execute2=yes\n"],
            ["execute = load_start=one\nexecute2 = load.start=one\n", "execute2 = load.start=one\n"],
            ["custom = same\ncustom = same\n", "custom = same\ncustom = same\n"],
            ["tracker_numwant = 1\rcheck_hash = yes\r", "trackers.numwant.set = 1\rpieces.hash.on_completion.set = yes\r"],
            ["tracker_numwant = 1\ncheck_hash = yes\r\n", "trackers.numwant.set = 1\r\npieces.hash.on_completion.set = yes\r\n"],
        ] as [$input, $expected]) {
            $this->assertSame($expected, \pmssRtorrentNormalizeLegacyTemplate($input));
        }
    }
}
