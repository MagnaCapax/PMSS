<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/RepoFileReadTrait.php';

class rtorrentConfigSyntaxCompatibilityTest extends TestCase
{
    use RepoFileReadTrait;

    /**
     * PMSS-owned rTorrent config files must avoid commands removed in 0.15.x.
     */
    public function testPmssRtorrentConfigsAvoidRemovedSchedulerAndExecuteAliases(): void
    {
        foreach ($this->rtorrentConfigFiles() as $relativePath) {
            $content = $this->readRepoFile($relativePath);
            $forbiddenPatterns = [
                '/^\s*schedule\s*=/m' => 'schedule',
                '/^\s*schedule_remove\s*=/m' => 'schedule_remove',
                '/^\s*execute\s*=/m' => 'execute',
            ];

            foreach ($forbiddenPatterns as $pattern => $label) {
                $this->assertTrue(
                    preg_match($pattern, $content) !== 1,
                    $relativePath.' still uses removed rTorrent command alias '.$label
                );
            }
        }
    }

    /**
     * The shipped skeleton and template should keep the RSS hook on supported syntax.
     */
    public function testPmssRtorrentConfigsKeepModernRssHooks(): void
    {
        $expectedRssHook = 'schedule2 = rss,0,1800,"execute.nothrow=sh,-c,php ~/www/rutorrent/plugins/rss/update.php& exit 0"';

        $templateContent = $this->readRepoFile('etc/seedbox/config/template.rtorrent.rc');
        $this->assertStringContainsString($expectedRssHook, $templateContent);

        $skeletonContent = $this->readRepoFile('etc/skel/.rtorrent.rc');
        $this->assertStringContainsString('schedule2 = watch_directory,1,1,"load.start_verbose=~/watch/*.torrent"', $skeletonContent);
        $this->assertStringContainsString($expectedRssHook, $skeletonContent);
    }

    /**
     * The skeleton should not ship rTorrent 0.9.8-incompatible legacy directives.
     */
    public function testSkeletonRtorrentConfigAvoidsRemovedAndDeprecatedLegacyOptions(): void
    {
        $content = $this->readRepoFile('etc/skel/.rtorrent.rc');
        $forbiddenLines = [
            'umask = 0002',
            'use_udp_trackers = yes',
            'hash_interval = 300',
            'hash_max_tries = 2',
            'tracker_numwant = -1',
            'port_range = 50000-60000',
            'check_hash = no',
        ];
        $requiredLines = [
            'trackers.numwant.set = -1',
            'trackers.use_udp.set = yes',
            'network.port_range.set = 50000-60000',
            'pieces.hash.on_completion.set = no',
        ];

        foreach ($forbiddenLines as $line) {
            $this->assertTrue(
                strpos($content, $line) === false,
                'Skeleton still ships legacy line '.$line
            );
        }

        foreach ($requiredLines as $line) {
            $this->assertStringContainsString($line, $content, 'Skeleton is missing modern line '.$line);
        }
    }

    /**
     * Limit the audit to PMSS-owned config files, excluding the frozen vendor tree.
     */
    private function rtorrentConfigFiles(): array
    {
        return [
            'etc/seedbox/config/template.rtorrent.rc',
            'etc/skel/.rtorrent.rc',
            'etc/skel/.rtorrent.rc.custom',
        ];
    }
}
