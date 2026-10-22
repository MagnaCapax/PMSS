<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Welcome home view helpers (ADR 0147): What's new selection, status chips and the app tiles. */
final class WelcomeHomeTest extends TestCase
{
    /**
     * Run PHP against welcome.php's helper tail in a child process and decode its JSON answer.
     * Everything from pmssWelcomeTrafficUpgradeUrlBuild() on is function definitions only.
     */
    private function runWelcomeHome(string $script): array
    {
        // Written per call: the harness removes each test's temporary files when the test ends.
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeTrafficUpgradeUrlBuild');
        $this->assertTrue($start !== false, 'welcome.php view helpers should remain present');
        $fixture = $this->pmssWriteFile(
            $this->pmssMakeTempPath('pmss-welcome-home-', '.php'),
            "<?php\nrequire_once ".var_export($this->pmssRepoPath('etc/skel/www/scriptsInc.php'), true).";\n"
            .preg_replace('/\?>\s*$/', '', substr($source, $start))
        );
        $output = $this->pmssRunInlinePhpRequire($fixture, $script, [], '2>&1');
        $this->pmssAssertStringNotContainsString('Undefined', $output);
        return $this->pmssDecodeJsonArray($output);
    }

    /** @return array<int,array{title:string,link:string,time:int}> */
    private function newsItems(string $prefix, int $count, int $newestTime): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['title' => $prefix.$i, 'link' => 'https://example.test/'.$prefix.$i, 'time' => $newestTime - $i + 1];
        }
        return $items;
    }

    private function newsSelect(array $announcements, array $articles, array $wiki): array
    {
        return $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeNewsSelect('
            .var_export($announcements, true).', '.var_export($articles, true).', '.var_export($wiki, true).'));');
    }

    public function testNewsSelectPinsNewestAnnouncementsAheadOfNewerBlogAndWiki(): void
    {
        $rows = $this->newsSelect($this->newsItems('A', 3, 100), $this->newsItems('B', 6, 200), $this->newsItems('W', 3, 300));

        $this->assertSame(6, count($rows));
        $this->assertSame(['A1', 'A2', 'W1', 'W2', 'B1', 'B2'], array_column($rows, 'title'));
        $this->assertSame(['News', 'News', 'Wiki', 'Wiki', 'Blog', 'Blog'], array_column($rows, 'kind'));
        $this->assertSame(['kind' => 'News', 'title' => 'A1', 'link' => 'https://example.test/A1', 'time' => 100], $rows[0]);
    }

    public function testNewsSelectCapsWikiAtTwoRows(): void
    {
        $rows = $this->newsSelect([], $this->newsItems('B', 6, 200), $this->newsItems('W', 5, 300));

        $this->assertSame(6, count($rows));
        $this->assertSame(['W1', 'W2', 'B1', 'B2', 'B3', 'B4'], array_column($rows, 'title'));
        $this->assertSame(2, count(array_keys(array_column($rows, 'kind'), 'Wiki')));
    }

    public function testNewsSelectKeepsShortListsWhole(): void
    {
        $rows = $this->newsSelect($this->newsItems('A', 1, 100), $this->newsItems('B', 1, 200), []);

        $this->assertSame(['A1', 'B1'], array_column($rows, 'title'));
        $this->assertSame([], $this->newsSelect([], [], []));
    }

    public function testStatusChipsRenderEachBonusState(): void
    {
        $states = [
            'percent' => ['unit' => 'percent', 'value' => 25, 'state' => 'applied'],
            'gib' => ['unit' => 'gib', 'value' => 50, 'state' => 'applied'],
            'gibLarge' => ['unit' => 'gib', 'value' => 1925, 'state' => 'applied'],
            'zero' => ['unit' => 'percent', 'value' => 0, 'state' => 'zero'],
            'absent' => ['unit' => 'percent', 'value' => 0, 'state' => 'absent'],
        ];
        $html = $this->runWelcomeHome(
            '$html = array(); foreach ('.var_export($states, true).' as $name => $state) { $html[$name] = pmssWelcomeHomeStatusChipsHtmlBuild($state, 0); }'
            .'$html["invalid"] = pmssWelcomeHomeStatusChipsHtmlBuild(null, 0);'
            .'$html["billed"] = pmssWelcomeHomeStatusChipsHtmlBuild('.var_export($states['gib'], true).', 42);'
            .'echo json_encode($html);'
        );

        $this->assertStringContainsAllStrings(['class="h-chip h-chip-bonus"', 'Loyalty bonus <b>+25%</b>', 'Bonus is applied on this server.'], $html['percent']);
        $this->assertStringContainsAllStrings(['Bonus disk <b>+50 GiB</b>', 'Additional disk space is included in your quota on this server.'], $html['gib']);
        $this->assertStringContainsString('Bonus disk <b>+1,925 GiB</b>', $html['gibLarge']);
        $this->pmssAssertStringNotContainsString('Bonus disk space:', $html['gibLarge']);
        $this->assertStringContainsAllStrings(['Loyalty bonus <b>0%</b>', 'No bonus is applied on this server.'], $html['zero']);
        foreach (['absent', 'invalid'] as $name) {
            $this->assertStringContainsString('how it works', $html[$name], $name);
            $this->pmssAssertStringNotContainsString('0%', $html[$name], $name);
        }
        foreach ($states as $name => $unused) {
            $this->pmssAssertStringNotContainsString('>Upgrade</a>', $html[$name], $name);
        }
        $this->assertStringContainsAllStrings(['Bonus disk <b>+50 GiB</b>', '>Upgrade</a>', 'type=configoptions&amp;id=42'], $html['billed']);
    }

    public function testAgentFirstOnlyWhenOpenClawRunsWithoutTorrentClients(): void
    {
        $cases = [
            'openclawOnly' => [['openclaw' => ['state' => 'running']], true],
            'torrentClientsOff' => [['openclaw' => ['state' => 'gave up'], 'rtorrent' => ['state' => 'off'], 'Deluge' => ['state' => 'off'], 'lighttpd' => ['state' => 'running']], true],
            'rtorrentOn' => [['openclaw' => ['state' => 'running'], 'rtorrent' => ['state' => 'not-running']], false],
            'qBittorrentOn' => [['openclaw' => ['state' => 'running'], 'rtorrent' => ['state' => 'off'], 'qBittorrent' => ['state' => 'installing']], false],
            'delugeOn' => [['openclaw' => ['state' => 'running'], 'Deluge' => ['state' => 'stopped']], false],
            'openclawOff' => [['openclaw' => ['state' => 'off']], false],
            'openclawAbsent' => [['rtorrent' => ['state' => 'off']], false],
            'noApps' => [[], false],
        ];
        $statuses = [];
        $expected = [];
        foreach ($cases as $name => list($apps, $agentFirst)) {
            $statuses[$name] = ['apps' => $apps];
            $expected[$name] = $agentFirst;
        }
        $statuses['missingAppsKey'] = [];
        $expected['missingAppsKey'] = false;

        $this->assertSame($expected, $this->runWelcomeHome(
            '$result = array(); foreach ('.var_export($statuses, true).' as $name => $status) { $result[$name] = pmssWelcomeHomeAgentFirst($status); }'
            .'echo json_encode($result);'
        ));
    }

    public function testAppsBuildDropsLighttpdAndAppsSwitchedOff(): void
    {
        $status = ['apps' => [
            'lighttpd' => ['state' => 'running', 'url' => 'https://render.test/user-alice/'],
            'rtorrent' => ['state' => 'running', 'url' => 'https://render.test/user-alice/rutorrent/'],
            'jellyfin' => ['state' => 'off', 'url' => 'https://render.test/jellyfin/'],
            'sonarr' => ['state' => 'running', 'url' => 'https://render.test/sonarr/'],
            'qBittorrent' => ['state' => 'stopped', 'url' => 'https://render.test/qbittorrent/'],
            'Deluge' => ['state' => 'off'],
            'openclaw' => ['state' => 'running', 'url' => 'https://render.test/openclaw/'],
        ]];
        $result = $this->runWelcomeHome(
            'echo json_encode(array('
            .'"plain" => pmssWelcomeHomeAppsBuild('.var_export($status, true).', false),'
            .'"agent" => pmssWelcomeHomeAppsBuild('.var_export($status, true).', true)'
            .'));'
        );

        foreach (['plain', 'agent'] as $mode) {
            $ids = array_column($result[$mode], 'id');
            sort($ids);
            $this->assertSame(['openclaw', 'qBittorrent', 'rtorrent', 'sonarr'], $ids, $mode);
        }

        $plain = array_column($result['plain'], null, 'id');
        $this->assertSame('ruTorrent', $plain['rtorrent']['name']);
        $this->assertSame('Your main torrent client', $plain['rtorrent']['desc']);
        $this->assertSame(true, $plain['rtorrent']['open']);
        $this->assertSame(false, $plain['qBittorrent']['open'], 'A stopped app offers Manage, not Open');
        $this->assertSame([false], array_values(array_unique(array_column($result['plain'], 'hero'))));

        $this->assertSame('openclaw', $result['agent'][0]['id']);
        $this->assertSame(true, $result['agent'][0]['hero']);
        $this->assertSame(1, count(array_filter(array_column($result['agent'], 'hero'))));
    }

    public function testAppsBuildOpensRtorrentWhileItIsNotRunning(): void
    {
        $apps = $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeAppsBuild('.var_export(['apps' => [
            'rtorrent' => ['state' => 'not-running', 'url' => 'https://render.test/user-alice/rutorrent/'],
            'sonarr' => ['state' => 'not-running', 'url' => 'https://render.test/sonarr/'],
            'radarr' => ['state' => 'running', 'url' => ''],
        ]], true).', false));');

        $byId = array_column($apps, null, 'id');
        $this->assertSame(true, $byId['rtorrent']['open'], 'ruTorrent still loads while rTorrent restarts');
        $this->assertSame(false, $byId['sonarr']['open']);
        $this->assertSame(false, $byId['radarr']['open'], 'An app without a URL has nothing to open');
    }
}
