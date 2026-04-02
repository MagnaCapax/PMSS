<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
putenv('PMSS_RTORRENT_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/rtorrent.php';

class RtorrentTemplateMigrationTest extends TestCase
{
    /**
     * Preserve tracker count intent while moving to the modern key name.
     */
    public function testRewritesLegacyTrackerNumwantDirective(): void
    {
        $input = "tracker_numwant = 42\n";

        $this->assertEquals(
            "trackers.numwant.set = 42\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Preserve UDP tracker intent instead of silently dropping the setting.
     */
    public function testRewritesLegacyUdpTrackerDirective(): void
    {
        $input = "use_udp_trackers = no\n";

        $this->assertEquals(
            "trackers.use_udp.set = no\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Upgrade both port-range and hash directives to supported names.
     */
    public function testRewritesLegacyPortRangeAndHashDirective(): void
    {
        $input = "port_range = 40000-45000\ncheck_hash = yes\n";

        $this->assertEquals(
            "network.port_range.set = 40000-45000\npieces.hash.on_completion.set = yes\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Upgrade removed scheduler aliases to the modern names used by 0.15.x.
     */
    public function testRewritesLegacySchedulerAliases(): void
    {
        $input = "schedule = watch_directory,15,1,\"load_start=~/watch/*.torrent\"\nschedule_remove = watch_directory\n";

        $this->assertEquals(
            "schedule2 = watch_directory,15,1,\"load_start=~/watch/*.torrent\"\nschedule_remove2 = watch_directory\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Rewrite legacy load/execute aliases without touching the dotted variants.
     */
    public function testRewritesLegacyLoadAndExecuteAliasesOnly(): void
    {
        $input = "load_start = ~/watch/example.torrent\nload_start_verbose = ~/watch/verbose.torrent\nexecute = sh,-c,echo ok\nexecute.nothrow = chmod,770,~/.rtorrent.socket\n";

        $this->assertEquals(
            "load.start = ~/watch/example.torrent\nload.start_verbose = ~/watch/verbose.torrent\nexecute2 = sh,-c,echo ok\nexecute.nothrow = chmod,770,~/.rtorrent.socket\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Drop directives that have no modern replacement in current PMSS configs.
     */
    public function testRemovesObsoleteLegacyDirectives(): void
    {
        $input = "directory = ~/data\numask = 0002\nhash_interval = 300\nhash_max_tries = 2\n";

        $this->assertEquals(
            "directory = ~/data\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Keep the normalized template idempotent when both old and new lines exist.
     */
    public function testDoesNotDuplicateExistingModernDirectives(): void
    {
        $input = "trackers.use_udp.set = yes\nuse_udp_trackers = yes\n";

        $this->assertEquals(
            "trackers.use_udp.set = yes\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }

    /**
     * Preserve unrelated content and line ending style while normalizing.
     */
    public function testPreservesUnrelatedLinesAndLineEndings(): void
    {
        $input = "directory = ~/data\r\ntracker_numwant = -1\r\ncustom = keep\r\n";

        $this->assertEquals(
            "directory = ~/data\r\ntrackers.numwant.set = -1\r\ncustom = keep\r\n",
            \pmssRtorrentNormalizeLegacyTemplate($input)
        );
    }
}
