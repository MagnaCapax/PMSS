<?php
namespace PMSS\Tests;

class rtorrentConfigSyntaxCompatibilityTest extends TestCase
{
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
     * Keep path handling local to the repo so the test stays hermetic.
     */
    private function readRepoFile(string $relativePath): string
    {
        $content = @file_get_contents($this->repoRoot().'/'.$relativePath);
        $this->assertTrue(is_string($content) && $content !== '', 'Failed to read '.$relativePath);
        return $content;
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

    private function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
