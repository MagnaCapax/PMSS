<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/nginxUserHosts.php';

/** Welcome home view helpers (ADR 0147, 0148): news selection, status chips and the app tiles. */
final class WelcomeHomeTest extends TestCase
{
    /**
     * Run PHP against welcome.php's helper tail in a child process and decode its JSON answer.
     * Everything from pmssWelcomeTrafficUpgradeUrlBuild() on is function definitions only.
     */
    private function runWelcomeHome(string $script, bool $offline = false, ?array $toggle = null): array
    {
        // Written per call: the harness removes each test's temporary files when the test ends.
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeTrafficUpgradeUrlBuild');
        $this->assertTrue($start !== false, 'welcome.php view helpers should remain present');
        $fixtureDir = $this->pmssMakeTempDir('pmss-welcome-home-');
        if ($toggle !== null) $this->pmssWriteFile($fixtureDir.'/toggleAppCatalog.php', '<?php return '.var_export($toggle, true).';');
        $fixture = $this->pmssWriteFile(
            $fixtureDir.'/welcome.php',
            "<?php\nrequire_once ".var_export($this->pmssRepoPath('etc/skel/www/scriptsInc.php'), true).";\n"
            .preg_replace('/\?>\s*$/', '', substr($source, $start))
        );
        // Offline: URL streams are off in the child, so a credit-service read fails at once instead of reaching the network.
        $output = $offline
            ? $this->pmssRunShellCommand(escapeshellarg(PHP_BINARY).' -d allow_url_fopen=0 -r '
                .escapeshellarg('require '.var_export($fixture, true).'; '.$script), [], '2>&1')
            : $this->pmssRunInlinePhpRequire($fixture, $script, [], '2>&1');
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

    private function newsSelect(array $announcements, array $articles): array
    {
        return $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeNewsSelect('
            .var_export($announcements, true).', '.var_export($articles, true).'));');
    }

    /** Freeze all tile actions and field order through the pre-existing grid entrypoint. */
    public function testAppPresentationActionsAndCatalogOverlay(): void
    {
        $cases = ['running' => 'open', 'not-running' => 'start', 'off' => 'start', 'stopped' => 'start',
            'failed' => 'start', 'gave up' => 'start', 'installing' => 'none', 'not secured' => 'manage',
            'absent' => 'setup', '' => 'manage', 'unknown' => 'manage'];
        foreach ($cases as $state => $action) {
            $tiles = $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeAppsBuild('.var_export(['apps' => [
                'qBittorrent' => ['state' => $state, 'url' => 'https://example.test/?x="&y=<'],
                'rtorrent' => ['state' => 'not-running', 'url' => '/rutorrent/'],
                'custom' => false, 'lighttpd' => ['state' => 'running'],
            ]], true).', false));', false, ['qBittorrent' => ['label' => 'Custom torrent label']]);
            $byId = array_column($tiles, null, 'id');
            $tile = $byId['qBittorrent'];
            $this->assertSame(['id', 'name', 'desc', 'state', 'url', 'open', 'action', 'restart', 'start', 'hero', 'more'], array_keys($tile));
            $this->assertSame('Custom torrent label', $tile['name']);
            $this->assertSame('A second torrent client with a modern web UI', $tile['desc']);
            $this->assertSame($action, $tile['action'], $state);
            $this->assertSame($state === 'running', $tile['restart']);
            $this->assertSame(false, $tile['start']);
            $this->assertSame('open', $byId['rtorrent']['action']);
            $this->assertSame(true, $byId['rtorrent']['start']);
            $this->assertSame('manage', $byId['custom']['action']);
            $this->assertFalse(isset($byId['lighttpd']));
        }
    }

    /** App kinds, unknown-ID fallbacks and media suggestions retain their original sets. */
    public function testAppIconsAndAbsentSuggestionsCharacterization(): void
    {
        $paths = ['torrent' => '<path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/>',
            'media' => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M10 9l4 2-4 2z"/><path d="M8 20h8"/>',
            'cloud' => '<path d="M7 18h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6 9.5 4.25 4.25 0 0 0 7 18z"/>',
            'agent' => '<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01"/>',
            'files' => '<path d="M4 6h6l2 2h8v10H4z"/>'];
        $groups = ['torrent' => ['rtorrent', 'qBittorrent', 'Deluge', 'qui'],
            'media' => ['jellyfin', 'sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'autobrr', 'Scryer'],
            'cloud' => ['rclone', 'cloudplow'], 'agent' => ['openclaw'], 'files' => ['unknown', '', 'Jellyfin', null, false, 0, [], ['rtorrent']]];
        foreach ($groups as $kind => $ids) {
            $icons = $this->runWelcomeHome('echo json_encode(array_map("pmssWelcomeHomeAppIcon", '.var_export($ids, true).'));');
            foreach ($icons as $icon) $this->assertSame('<svg class="h-ico" viewBox="0 0 24 24" aria-hidden="true">'.$paths[$kind].'</svg>', $icon);
        }
        $tiles = $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeAppsBuild([], false));');
        $this->assertSame(['jellyfin', 'sonarr', 'radarr', 'prowlarr'], array_column($tiles, 'id'));
        $this->assertSame(array_fill(0, 4, 'setup'), array_column($tiles, 'action'));
    }

    public function testNewsSelectKeepsFiveAnnouncementsAndTenArticlesNewestFirst(): void
    {
        $news = $this->newsSelect($this->newsItems('A', 7, 100), array_reverse($this->newsItems('B', 12, 200)));

        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'A5'], array_column($news['announcements'], 'title'));
        $this->assertSame(['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8', 'B9', 'B10'], array_column($news['articles'], 'title'));
        $this->assertSame(['title' => 'A1', 'link' => 'https://example.test/A1', 'time' => 100], $news['announcements'][0]);
    }

    public function testNewsSelectKeepsShortListsWhole(): void
    {
        $news = $this->newsSelect($this->newsItems('A', 1, 100), $this->newsItems('B', 1, 200));

        $this->assertSame(['A1'], array_column($news['announcements'], 'title'));
        $this->assertSame(['B1'], array_column($news['articles'], 'title'));
        $this->assertSame(['announcements' => [], 'articles' => []], $this->newsSelect([], []));
    }

    public function testNewsPartsRenderHeadingsAndNoWikiPart(): void
    {
        $html = $this->runWelcomeHome('echo json_encode(array('
            .'pmssWelcomeHomeNewsPartHtmlBuild("Announcements", '.var_export($this->newsItems('A', 1, 100), true).', "https://example.test/all"),'
            .'pmssWelcomeHomeNewsPartHtmlBuild("Articles", array()),'
            .'pmssWelcomeHomeNewsPartHtmlBuild("Articles", '.var_export($this->newsItems('B', 1, 200), true).', "", false)));');

        $this->assertStringContainsString('<h3 class="h-news-sub">Announcements <a href="https://example.test/all"', $html[0]);
        $this->assertStringContainsString('<li data-ts="100">', $html[0]);
        $this->assertSame('', $html[1]);
        $this->assertStringContainsString('<time', $html[0]);
        $this->assertStringNotContainsString('<time', $html[2]);
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

        $first = function (array $tiles) { return array_column(array_filter($tiles, function ($t) { return !$t['more']; }), 'id'); };
        // Running apps first, then the common apps the account does not run, to fill three rows of three.
        $this->assertSame(['rtorrent', 'qBittorrent', 'sonarr', 'openclaw', 'Deluge', 'jellyfin', 'radarr', 'prowlarr'], $first($result['plain']));
        $this->assertFalse(in_array('lighttpd', array_column($result['plain'], 'id'), true), 'lighttpd is not an app tile');

        $plain = array_column($result['plain'], null, 'id');
        $this->assertSame('ruTorrent', $plain['rtorrent']['name']);
        $this->assertSame('Your main torrent client', $plain['rtorrent']['desc']);
        $this->assertSame(['open', true], [$plain['rtorrent']['action'], $plain['rtorrent']['restart']]);
        $this->assertSame(['start', false], [$plain['qBittorrent']['action'], $plain['qBittorrent']['open']], 'A stopped app starts with one click');
        $this->assertSame('start', $plain['Deluge']['action'], 'An app switched off starts with one click');
        $this->assertSame('setup', $plain['radarr']['action'], 'A media app never installed is set up in Configure');
        $this->assertSame('absent', $plain['radarr']['state']);
        $this->assertSame([false], array_values(array_unique(array_column($result['plain'], 'hero'))));

        $this->assertSame('openclaw', $result['agent'][0]['id']);
        $this->assertSame(true, $result['agent'][0]['hero']);
        $this->assertSame(1, count(array_filter(array_column($result['agent'], 'hero'))));
        $agentMore = array_column(array_filter($result['agent'], function ($t) { return $t['more']; }), 'id');
        $this->assertTrue(in_array('Deluge', $agentMore, true),'An OpenClaw-first account is not nudged back to a torrent client in the first rows');
    }

    private const CREDITS_KEY = 'vsk-abcdefghijklmnopqrstuvwx';

    /** A temporary account home holding ~/.virsilipasKey with the given content and mode; null content leaves no key file. */
    private function creditsHome(?string $content, int $mode = 0600): string
    {
        $home = $this->pmssMakeTempDir('pmss-welcome-credits-', 0700);
        if ($content !== null) {
            $this->pmssWriteFile($home.'/.virsilipasKey', $content);
            chmod($home.'/.virsilipasKey', $mode);
        }
        return $home;
    }

    /** Write the per-account credit cache as pmssWelcomeHomeCreditsRead() stores it. */
    private function creditsCacheWrite(string $home, string $key, int $age, array $figures): void
    {
        $this->pmssWriteFile($home.'/.cache/pmss-welcome/credits.json', (string) json_encode(array(
            'at' => time() - $age, 'key' => substr(hash('sha256', $key), 0, 12), 'figures' => $figures)), 0700);
    }

    /** @param array<string,string> $homes */
    private function creditsCall(string $function, array $homes): array
    {
        return $this->runWelcomeHome('$result = array(); foreach ('.var_export($homes, true).' as $name => $home) { $result[$name] = '
            .$function.'($home); } echo json_encode($result);', true);
    }

    public function testCreditsKeyReadAcceptsOnlyPrivateWellFormedKeyForTheCreditService(): void
    {
        $key = 'key='.self::CREDITS_KEY."\n";
        $endpoint = "endpoint=https://virsilipas.mcx.fi/v1\n";
        $target = $this->creditsHome($endpoint.$key);
        $link = $this->creditsHome(null);
        $this->pmssCreateSymlinkOrSkip($target.'/.virsilipasKey', $link.'/.virsilipasKey');
        $homes = array(
            'valid' => $target,
            'trailingSlash' => $this->creditsHome("endpoint=https://virsilipas.mcx.fi/v1/\n".$key),
            'defaultEndpoint' => $this->creditsHome($key),
            'readable' => $this->creditsHome($endpoint.$key, 0644),
            'symlink' => $link,
            'foreignEndpoint' => $this->creditsHome("endpoint=https://credits.example.test/v1\n".$key),
            'plainHttp' => $this->creditsHome("endpoint=http://virsilipas.mcx.fi/v1\n".$key),
            'shortKey' => $this->creditsHome($endpoint."key=vsk-short\n"),
            'otherPrefix' => $this->creditsHome($endpoint."key=sk-abcdefghijklmnopqrstuvwx\n"),
            'badCharacter' => $this->creditsHome($endpoint."key=vsk-abcdefghij.lmnopqrstuvwx\n"),
            'missing' => $this->creditsHome(null),
        );

        $result = $this->creditsCall('pmssWelcomeHomeCreditsKeyRead', $homes);

        $expected = array('key' => self::CREDITS_KEY, 'endpoint' => 'https://virsilipas.mcx.fi/v1');
        foreach (array('valid', 'trailingSlash', 'defaultEndpoint') as $name) {
            $this->assertSame($expected, $result[$name], $name);
        }
        foreach (array('readable', 'symlink', 'foreignEndpoint', 'plainHttp', 'shortKey', 'otherPrefix', 'badCharacter', 'missing') as $name) {
            $this->assertSame(null, $result[$name], $name);
        }
    }

    public function testCreditsReadUsesFreshCacheForThisKey(): void
    {
        $file = "endpoint=https://virsilipas.mcx.fi/v1\nkey=".self::CREDITS_KEY."\n";
        $funded = $this->creditsHome($file);
        $this->creditsCacheWrite($funded, self::CREDITS_KEY, 0, array('balance' => 900000, 'tokens_30d' => 1234567, 'pct_left' => 45.5));
        $empty = $this->creditsHome($file);
        $this->creditsCacheWrite($empty, self::CREDITS_KEY, 0, array('balance' => 0, 'tokens_30d' => 1234567, 'pct_left' => 0));

        $result = $this->creditsCall('pmssWelcomeHomeCreditsRead', array('funded' => $funded, 'empty' => $empty, 'none' => $this->creditsHome(null)));

        $ok = $result['funded'];
        $this->assertSame('ok', $ok['state']);
        $this->assertSame(900000, $ok['balance']);
        $this->assertSame('900k', $ok['value']);
        $this->assertSame(50, $ok['questions']);
        $this->assertSame(45.5, $ok['pct_left']);
        $this->assertSame(1234567, $ok['tokens_30d']);
        $this->assertSame(0, strpos($ok['text'], 'About 50 Pikkumies questions'), $ok['text']);
        $this->assertStringContainsAllStrings(array('≈50 questions', '1.2M tokens used in 30 days'), $ok['meta']);
        $this->pmssAssertStringNotContainsString(self::CREDITS_KEY, json_encode($result), 'The key never leaves the server in the figures');

        $this->assertSame('ok', $result['empty']['state']);
        $this->assertSame('0', $result['empty']['value']);
        $this->assertSame(null, $result['empty']['questions']);
        $this->assertSame('', $result['empty']['text']);
        $this->assertStringContainsAllStrings(array('1.2M tokens used in 30 days', 'more are added with each paid invoice'), $result['empty']['meta']);

        $this->assertSame('none', $result['none']['state']);
        $this->assertSame(null, $result['none']['questions']);
    }

    public function testCreditsReadIgnoresCacheOfAnotherKeyAndExpiredCopies(): void
    {
        $file = "endpoint=https://virsilipas.mcx.fi/v1\nkey=".self::CREDITS_KEY."\n";
        $figures = array('balance' => 777000, 'tokens_30d' => 5, 'pct_left' => 12.5);
        $otherKey = $this->creditsHome($file);
        $this->creditsCacheWrite($otherKey, 'vsk-zyxwvutsrqponmlkjihgfedc', 0, $figures);
        $stale = $this->creditsHome($file);
        $this->creditsCacheWrite($stale, self::CREDITS_KEY, 600, $figures);
        $expired = $this->creditsHome($file);
        $this->creditsCacheWrite($expired, self::CREDITS_KEY, 7200, $figures);

        // URL streams are off in the child, so the live read fails and only the cache rules decide.
        $result = $this->creditsCall('pmssWelcomeHomeCreditsRead', array('otherKey' => $otherKey, 'stale' => $stale, 'expired' => $expired));

        foreach (array('otherKey', 'expired') as $name) {
            $this->assertSame('unknown', $result[$name]['state'], $name);
            $this->assertSame(0, $result[$name]['balance'], $name);
            $this->assertSame('', $result[$name]['value'], $name);
        }
        $this->assertSame('ok', $result['stale']['state'], 'A same-key copy under an hour old stands in while the service is unreachable');
        $this->assertSame(777000, $result['stale']['balance']);
        $this->assertSame('777k', $result['stale']['value']);
    }

    public function testCreditsCompactRoundsDown(): void
    {
        $this->assertSame(
            array('4.9M', '1M', '35.4k', '1.9k', '999', '0'),
            $this->runWelcomeHome('echo json_encode(array_map("pmssWelcomeHomeCreditsCompact", array(4960000, 1000000, 35400, 1999, 999, 0)));')
        );
    }

    public function testAppsGridFillsThreeRowsAndKeepsTheRestUnderMoreApps(): void
    {
        $status = ['apps' => ['rtorrent' => ['state' => 'running', 'url' => 'https://render.test/user-alice/rutorrent/'],
            'qBittorrent' => ['state' => 'off'], 'Deluge' => ['state' => 'off'], 'rclone' => ['state' => 'off'], 'openclaw' => ['state' => 'off'],
            'slskd' => ['state' => 'off'], 'sabnzbd' => ['state' => 'running', 'url' => 'https://render.test/sabnzbd/']]];
        $tiles = $this->runWelcomeHome('echo json_encode(pmssWelcomeHomeAppsBuild('.var_export($status, true).', false));');
        $first = array_column(array_filter($tiles, function ($t) { return !$t['more']; }), 'id');
        $this->assertSame(['rtorrent', 'sabnzbd', 'qBittorrent', 'Deluge', 'jellyfin', 'sonarr', 'radarr', 'prowlarr', 'rclone'], $first,
            'nine tiles: what runs, then the common apps');
        $more = array_column(array_filter($tiles, function ($t) { return $t['more']; }), 'id');
        $this->assertSame(['openclaw', 'slskd'], $more, 'the rest is one click away under More apps');

        $html = $this->runWelcomeHome('echo json_encode(array("html" => pmssWelcomeHomeAppsHtmlBuild(pmssWelcomeHomeAppsBuild('.var_export($status, true).', false))));')['html'];
        $this->assertStringContainsAllStrings(array('data-tile-action="start"', 'data-tile-action="restart"', 'More apps (2)',
            'All apps and settings in Configure', "pmssWelcomeTabOpen('#pmssconfigure', this)", 'h-app h-app-ghost h-app-more" data-app="slskd"'), $html);
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
        $this->assertSame(true, $byId['rtorrent']['start'], 'a stopped rTorrent can be started from its tile');
        $this->assertSame(false, $byId['radarr']['start']);

        $html = $this->runWelcomeHome('echo json_encode(array("html" => pmssWelcomeHomeAppTileHtml('.var_export($byId['rtorrent'], true).')));')['html'];
        $this->assertStringContainsAllStrings(array('>Open<span class="h-sr"> ruTorrent</span></a>', 'data-tile-action="start">Start'), $html);
        $this->pmssAssertStringNotContainsString('data-tile-action="restart"', $html);
    }

    public function testSupportRedactRemovesPasswordsAndKeysButKeepsOrdinaryText(): void
    {
        $ordinary = "rTorrent stopped after the update.\nPassing traffic is slow since Monday.\nMy password is not working, the token is expired. Pass: 2 of 3.";
        $result = $this->runWelcomeHome('echo json_encode(array_map("pmssWelcomeHomeSupportRedact", '.var_export(array(
            'password' => 'password: hunter2',
            'pwd' => 'my pwd is abc123',
            'key' => 'see vsk-abcdefghij0123456789 here',
            'ordinary' => $ordinary,
        ), true).'));');

        $this->assertSame('password: [removed]', $result['password']);
        $this->assertSame('my pwd is [removed]', $result['pwd']);
        $this->assertSame('see [removed key] here', $result['key']);
        $this->assertSame($ordinary, $result['ordinary']);
        $this->pmssAssertStringNotContainsString('hunter2', json_encode($result));
        $this->pmssAssertStringNotContainsString('vsk-', json_encode($result));
    }

    public function testSupportRequestBuildTakesSubjectFromTopicAndFirstLine(): void
    {
        $long = str_repeat('x', 120);
        $result = $this->runWelcomeHome('echo json_encode(array('
            .'"down" => pmssWelcomeHomeSupportRequestBuild("down", "rTorrent stopped\r\nmore", "  user@example.test \n"),'
            .'"long" => pmssWelcomeHomeSupportRequestBuild("working", '.var_export($long."\nsecond line", true).', "user@example.test"),'
            .'"unknown" => pmssWelcomeHomeSupportRequestBuild("nonsense", "Something odd", "user@example.test")'
            .'));');

        $down = $result['down'];
        $this->assertSame('Server down: rTorrent stopped', $down['subject']);
        $this->assertSame('user@example.test', $down['email']);
        $this->assertSame('Medium', $down['priority']);
        $this->assertStringContainsAllStrings(array("rTorrent stopped\nmore", "(Sent from the panel's Get help form, topic: Server down)"), $down['message']);

        $this->assertSame('Not working: '.str_repeat('x', 79).'…', $result['long']['subject'], 'A long first line is cut to 80 characters');
        $this->assertStringContainsString($long."\nsecond line", $result['long']['message'], 'The message keeps the whole text');

        $this->assertSame('Other: Something odd', $result['unknown']['subject']);
        $this->assertStringContainsString('topic: Other)', $result['unknown']['message']);
    }

    public function testSupportAnswerTellsTheCustomerWhatHappened(): void
    {
        $cases = array(
            'opened' => array(201, '{"ticket":"123456"}'),
            'malformedTicket' => array(201, '{"ticket":"12 34<b>"}'),
            'unauthorized' => array(401, '{"error":"unauthorized"}'),
            'rateLimited' => array(429, ''),
            'tooLong' => array(413, ''),
            'badRequest' => array(400, '{"error":"bad"}'),
            'unreachable' => array(0, false),
            'serverError' => array(503, '<html>'),
        );
        $result = $this->runWelcomeHome('$result = array(); foreach ('.var_export($cases, true).' as $name => $case) {'
            .' $result[$name] = pmssWelcomeHomeSupportAnswer($case[0], $case[1]); } echo json_encode($result);');

        $this->assertSame(true, $result['opened']['ok']);
        $this->assertSame('123456', $result['opened']['ticket']);
        $this->assertStringContainsString('Ticket #123456 opened', $result['opened']['message']);

        $this->assertSame(true, $result['malformedTicket']['ok']);
        $this->assertSame('', $result['malformedTicket']['ticket']);
        $this->pmssAssertStringNotContainsString('<b>', $result['malformedTicket']['message']);

        $failures = array('unauthorized', 'rateLimited', 'tooLong', 'badRequest');
        foreach (array_merge($failures, array('unreachable', 'serverError')) as $name) {
            $this->assertSame(false, $result[$name]['ok'], $name);
            $this->assertSame('', $result[$name]['ticket'], $name);
        }
        $this->assertStringContainsString('not accepted', $result['unauthorized']['message']);
        $this->assertStringContainsString('Too many tickets', $result['rateLimited']['message']);
        $this->assertStringContainsString('too long', $result['tooLong']['message']);
        $this->assertStringContainsString('could not be opened as written', $result['badRequest']['message']);
        $messages = array_map(function (string $name) use ($result): string { return $result[$name]['message']; }, $failures);
        $this->assertSame(count($failures), count(array_unique($messages)), 'Each refusal has its own message');
        foreach (array('unreachable', 'serverError') as $name) {
            $this->assertStringContainsString('could not be reached', $result[$name]['message'], $name);
        }
    }

    public function testSupportEmailDefaultReadsOnlyARegularFileWithAValidEmail(): void
    {
        $valid = $this->pmssMakeTempDir('pmss-welcome-support-', 0700);
        $this->pmssWriteFile($valid.'/.notifyEmail', " user@example.test\n");
        $invalid = $this->pmssMakeTempDir('pmss-welcome-support-', 0700);
        $this->pmssWriteFile($invalid.'/.notifyEmail', "not an email\n");
        $link = $this->pmssMakeTempDir('pmss-welcome-support-', 0700);
        $this->pmssCreateSymlinkOrSkip($valid.'/.notifyEmail', $link.'/.notifyEmail');
        $missing = $this->pmssMakeTempDir('pmss-welcome-support-', 0700);

        $result = $this->runWelcomeHome('$result = array(); foreach ('.var_export(array(
            'valid' => $valid, 'invalid' => $invalid, 'symlink' => $link, 'missing' => $missing,
        ), true).' as $name => $home) { $result[$name] = pmssWelcomeHomeSupportEmailDefault($home); } echo json_encode($result);');

        $this->assertSame(array('valid' => 'user@example.test', 'invalid' => '', 'symlink' => '', 'missing' => ''), $result);
    }

    public function testSupportPikkumiesPromptFramesTheTextAndStaysUnder4000(): void
    {
        $result = $this->runWelcomeHome('$tail = "unless I say so.";'
            .' $email = " My email for the ticket: a@example.test.";'
            .' $length = function ($text) { return function_exists("mb_strlen") ? mb_strlen($text, "UTF-8") : strlen($text); };'
            .' $long = pmssWelcomeHomeSupportPikkumiesPrompt("working", str_repeat("z", 10000), "a@example.test");'
            .' $wide = pmssWelcomeHomeSupportPikkumiesPrompt("slow", str_repeat("ä", 10000));'
            .' echo json_encode(array('
            .'"short" => pmssWelcomeHomeSupportPikkumiesPrompt("working", "rTorrent stopped\r\npassword: hunter2", " a@example.test "),'
            .'"noEmail" => pmssWelcomeHomeSupportPikkumiesPrompt("slow", "It is slow", "not an email"),'
            .'"unknown" => pmssWelcomeHomeSupportPikkumiesPrompt("nonsense", "Something odd"),'
            .'"longLength" => $length($long), "longEnd" => substr($long, -strlen($email)) === $email, "longHead" => strpos($long, "Support request from the panel") === 0,'
            .'"wideLength" => $length($wide), "wideEnd" => substr($wide, -strlen($tail)) === $tail'
            .'));');

        $this->assertStringContainsAllStrings(array(
            "Support request from the panel's Get help form (topic: Not working).",
            "rTorrent stopped\npassword: [removed]",
            'open a support ticket with your findings',
            'My email for the ticket: a@example.test.',
            "Result: All fine, no ticket needed.\n",
            "Result: Fixed, no ticket needed: <what you changed, in a few words>.\n",
            "Result: Not fixed, I opened ticket #<number>.\n",
            "Result: Not fixed, ticket not sent: <why>.\n",
            'no commands, file paths, code or logs',
            'If everything is fine, or you fixed it, do not open a ticket.',
        ), $result['short']);
        $this->pmssAssertStringNotContainsString('whether I still want', $result['short'], 'The answer says no ticket is needed; it does not ask');
        $this->pmssAssertStringNotContainsString('hunter2', $result['short'], 'A password in the text never reaches Pikkumies');
        $this->pmssAssertStringNotContainsString("\r", $result['short']);

        $this->assertStringContainsAllStrings(array('topic: Slow', 'It is slow', 'open a support ticket with your findings'), $result['noEmail']);
        $this->pmssAssertStringNotContainsString('My email for the ticket', $result['noEmail'], 'An invalid email is left out');
        $this->assertStringContainsString('topic: Other', $result['unknown']);
        $this->pmssAssertStringNotContainsString('My email for the ticket', $result['unknown']);

        $this->assertTrue($result['longLength'] <= 4000, 'A 10,000-character text is cut to fit 4000 characters in all: '.$result['longLength']);
        $this->assertTrue($result['longLength'] > 3500, 'The cut keeps as much text as fits: '.$result['longLength']);
        $this->assertSame(true, $result['longHead'], 'The frame head stays');
        $this->assertSame(true, $result['longEnd'], 'The frame tail and email stay after the cut');
        $this->assertTrue($result['wideLength'] <= 4000, 'Multibyte text is counted in characters: '.$result['wideLength']);
        $this->assertSame(true, $result['wideEnd'], 'The frame tail stays after a multibyte cut');
    }

    public function testSupportPikkumiesAnswerMapsAskExitCodes(): void
    {
        $result = $this->runWelcomeHome('$result = array(); foreach (array(0, 2, 3, 4, 5, 6, 1, 127, 124, -1) as $code) {'
            .' $result["c".$code] = pmssWelcomeHomeSupportPikkumiesAnswer($code); } echo json_encode($result);');

        $this->assertSame(true, $result['c0']['ok']);
        $this->assertSame(true, $result['c0']['pikkumies']);
        $this->assertStringContainsString('Pikkumies is checking it now', $result['c0']['message']);

        $known = array();
        foreach (array(2, 3, 4, 5, 6) as $code) {
            $answer = $result['c'.$code];
            $this->assertSame(false, $answer['ok'], 'exit '.$code);
            $this->assertSame(true, $answer['pikkumies'], 'exit '.$code);
            $this->assertStringContainsString('support now', $answer['message'], 'exit '.$code);
            $known[] = $answer['message'];
        }
        $this->assertSame(5, count(array_unique($known)), 'Each known refusal has its own message');
        $this->assertStringContainsString('one-time setup', $result['c2']['message']);
        $this->assertStringContainsString('busy with another question', $result['c6']['message']);

        $fallback = $result['c1']['message'];
        $this->assertStringContainsString('support now', $fallback);
        $this->assertFalse(in_array($fallback, $known, true), 'An unknown exit code does not borrow a known message');
        foreach (array(1, 127, 124, -1) as $code) {
            $this->assertSame(false, $result['c'.$code]['ok'], 'exit '.$code);
            $this->assertSame($fallback, $result['c'.$code]['message'], 'exit '.$code);
        }
    }

    /** A temporary account home; with $script, an executable fake ~/.mcxPikkumies/pikkumies/bin/pikkumies running it under /bin/sh. */
    private function supportPikkumiesHome(?string $script, array $libs = array('EternalAsk.php', 'EternalActivity.php')): string
    {
        $home = $this->pmssMakeTempDir('pmss-welcome-pikkumies-', 0700);
        if ($script !== null) {
            $this->pmssWriteExecutableFile($home.'/.mcxPikkumies/pikkumies/bin/pikkumies', "#!/bin/sh\n".$script, 0700, 0700);
            foreach ($libs as $lib) {
                $this->pmssWriteFile($home.'/.mcxPikkumies/pikkumies/lib/'.$lib, "<?php\n");
            }
        }
        return $home;
    }

    public function testSupportPikkumiesSendRefusesServerDownAndBillingWithoutRunningAnything(): void
    {
        // The fake leaves a marker next to itself when run; the state reader stub records every call.
        $armed = $this->supportPikkumiesHome("touch \"\$0.ran\"\nexit 0\n");
        $binary = $armed.'/.mcxPikkumies/pikkumies/bin/pikkumies';
        $cases = array(
            'down' => array($armed, 'down'),
            'billing' => array($armed, 'billing'),
            'other' => array($armed, 'nonsense'),
            'noBinary' => array($this->supportPikkumiesHome(null), 'working'),
            'noBinarySlow' => array($this->supportPikkumiesHome(null), 'slow'),
        );
        $result = $this->runWelcomeHome('function pmssWelcomePikkumiesStateRead($home, $name) { $GLOBALS["stateReads"][] = $home;'
            .' return array("visible" => true, "origin" => "https://example.test"); }'
            .' $GLOBALS["stateReads"] = array(); $result = array(); foreach ('.var_export($cases, true).' as $name => $case) {'
            .' $before = count($GLOBALS["stateReads"]);'
            .' $result[$name] = pmssWelcomeHomeSupportPikkumiesSend($case[0], $case[1], "rTorrent stopped", "abcdef0123456789", "a@example.test");'
            .' $result[$name]["stateRead"] = count($GLOBALS["stateReads"]) > $before; } echo json_encode($result);', true);

        foreach (array('down', 'billing', 'other') as $name) {
            $this->assertSame(false, $result[$name]['ok'], $name);
            $this->assertSame(true, $result[$name]['pikkumies'], $name);
            $this->assertSame('This topic goes straight to support.', $result[$name]['message'], $name);
            $this->assertSame(false, $result[$name]['stateRead'], $name.' is refused before anything is read');
        }
        $this->assertFalse(file_exists($binary.'.ran'), 'Server down and Billing never run Pikkumies');

        foreach (array('noBinary', 'noBinarySlow') as $name) {
            $this->assertSame(false, $result[$name]['ok'], $name);
            $this->assertSame('Pikkumies is not available on this account. Send this to support now.', $result[$name]['message'], $name);
        }
    }

    public function testSupportPikkumiesSendNeedsEnoughCreditsForACheck(): void
    {
        // Each home has an armed fake, a key and a fresh credit cache; the fake leaves a marker when run.
        $homes = array();
        foreach (array('low' => 44602, 'empty' => 0, 'enough' => 60000) as $name => $balance) {
            $home = $this->supportPikkumiesHome("touch \"\$0.ran\"\nexit 0\n");
            $this->pmssWriteFile($home.'/.virsilipasKey', 'key='.self::CREDITS_KEY."\n");
            chmod($home.'/.virsilipasKey', 0600);
            $this->creditsCacheWrite($home, self::CREDITS_KEY, 5, array('balance' => $balance, 'tokens_30d' => 0, 'pct_left' => null));
            $homes[$name] = $home;
        }
        $result = $this->runWelcomeHome('function pmssWelcomePikkumiesStateRead($home, $name) { return array("visible" => true); }'
            .' $result = array(); foreach ('.var_export($homes, true).' as $name => $home) {'
            .' $result[$name] = pmssWelcomeHomeSupportPikkumiesSend($home, "working", "rTorrent stopped", "abcdef0123456789"); } echo json_encode($result);', true);

        foreach (array('low', 'empty') as $name) {
            $this->assertSame(false, $result[$name]['ok'], $name);
            $this->assertSame('Your AI credits are too low for Pikkumies to look into this. Send this to support now.', $result[$name]['message'], $name);
            $this->assertFalse(file_exists($homes[$name].'/.mcxPikkumies/pikkumies/bin/pikkumies.ran'), $name.' never runs Pikkumies');
        }
        $this->assertSame(true, $result['enough']['ok']);
        $this->assertTrue(file_exists($homes['enough'].'/.mcxPikkumies/pikkumies/bin/pikkumies.ran'), '60,000 credits hand it to Pikkumies');
    }

    public function testSupportActivityReadsTheCommandAndCleansTheLine(): void
    {
        // The fake answers only `eternal activity --json`, from a JSON file written next to it.
        $answerer = "if [ \"\$*\" = \"eternal activity --json\" ]; then cat \"\$0.json\"; exit 0; fi\nexit 1\n";
        $homes = array();
        $line = "Checking rTorrent\u{0007}now\u{0085}".str_repeat('y', 300);
        foreach (array(
            'busy' => array('state' => 'busy', 'line' => 'terminal: cat ~/.rtorrent.rc', 'at' => 1760000005, 'said' => $line, 'said_at' => 1760000004),
            'badState' => array('state' => '<b>BUSY</b>', 'line' => 'reading the log'),
            'lineOnly' => array('state' => 'busy', 'line' => 'terminal: df -h /home', 'at' => 1760000009),
            'answered' => array('state' => 'idle', 'line' => 'read_file: /home/x', 'at' => 1760000020, 'said' => 'Result: All fine, no ticket needed. Your disk is 41% full.', 'said_at' => 1760000019),
        ) as $name => $answer) {
            $homes[$name] = $this->supportPikkumiesHome($answerer);
            $this->pmssWriteFile($homes[$name].'/.mcxPikkumies/pikkumies/bin/pikkumies.json', (string) json_encode($answer));
        }
        $homes['notJson'] = $this->supportPikkumiesHome("echo 'not json'\nexit 0\n");
        $homes['failed'] = $this->supportPikkumiesHome("echo '{\"state\":\"busy\",\"line\":\"x\"}'\nexit 1\n");
        $homes['noBinary'] = $this->supportPikkumiesHome(null);
        // A build without the command's code would take the words as a question to the AI: never run it.
        $homes['oldBuild'] = $this->supportPikkumiesHome("touch \"\$0.ran\"\nexit 0\n", array('EternalAsk.php'));
        // Where Pikkumies windows do not run, `eternal ...` is a question too.
        $homes['notVisible'] = $this->supportPikkumiesHome("touch \"\$0.ran\"\nexit 0\n");
        $this->pmssWriteFile($homes['notVisible'].'/.invisible', "1\n");

        // ActivityEmit sends headers (ignored by the CLI) and echoes JSON; each answer is captured on its own.
        $raw = $this->runWelcomeHome('function pmssWelcomePikkumiesStateRead($home, $name) { return array("visible" => !is_file($home . "/.invisible"), "origin" => ""); }'
            .' $result = array(); foreach ('.var_export($homes, true).' as $name => $home) {'
            .' ob_start(); pmssWelcomeHomeSupportActivityEmit($home); $result[$name] = ob_get_clean(); } echo json_encode($result);', true);
        $result = array();
        foreach ($raw as $name => $body) {
            $result[$name] = $this->pmssDecodeJsonArray($body, $name.' answers JSON');
        }

        $this->assertSame('busy', $result['busy']['state']);
        $this->assertSame(1760000005, $result['busy']['at']);
        $this->assertStringContainsString('Checking rTorrent now', $result['busy']['said'], 'Control characters become spaces');
        $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $result['busy']['said']), 'No control characters reach the page');
        $length = function_exists('mb_strlen') ? mb_strlen($result['busy']['said'], 'UTF-8') : strlen($result['busy']['said']);
        $this->assertTrue($length <= 200, 'Pikkumies\' words are cut to 200 characters: '.$length);
        foreach ($result as $name => $answer) {
            $this->assertFalse(array_key_exists('line', $answer), $name.': the raw activity line (a command, path or argument) never reaches the page');
            $this->pmssAssertStringNotContainsString('terminal:', (string) json_encode($answer), $name);
        }
        // A Pikkumies build without said: progress shows no words at all, never the tool line.
        $this->assertSame(array('state' => 'busy', 'at' => 1760000009, 'said' => '', 'said_at' => 0), $result['lineOnly']);

        $this->assertSame(array('state' => '', 'at' => 0, 'said' => '', 'said_at' => 0), $result['badState']);
        foreach (array('notJson', 'failed', 'noBinary', 'oldBuild', 'notVisible') as $name) {
            $this->assertSame(array('state' => '', 'at' => 0, 'said' => '', 'said_at' => 0), $result[$name], $name);
        }

        // The answer start is logged once, by the server, with no question text.
        $log = $homes['answered'].'/.pmss/support-log.jsonl';
        $rows = array_map(function ($row) { return json_decode($row, true); }, file($log, FILE_IGNORE_NEW_LINES));
        $this->assertSame(1, count($rows), 'one answered row');
        $this->assertSame('Result: All fine, no ticket needed.', $result['answered']['said'], 'An answer reaches the page as its Result sentence only');
        $this->assertSame(array('answered', 'pikkumies', 'Result: All fine, no ticket needed.', 1760000019),
            array($rows[0]['event'], $rows[0]['route'], $rows[0]['result'], $rows[0]['said_at']));
        $this->runWelcomeHome('function pmssWelcomePikkumiesStateRead($home, $name) { return array("visible" => true, "origin" => ""); }'
            .' ob_start(); pmssWelcomeHomeSupportActivityEmit('.var_export($homes['answered'], true).'); ob_end_clean(); echo "[]";', true);
        $this->assertSame(1, count(file($log)), 'a second poll of the same answer adds no row');
        foreach (array('busy', 'lineOnly') as $name) {
            $this->assertFalse(is_file($homes[$name].'/.pmss/support-log.jsonl'), $name.': no Result, no row');
        }
        $this->assertFalse(file_exists($homes['oldBuild'].'/.mcxPikkumies/pikkumies/bin/pikkumies.ran'), 'A build without the activity command is never run');
        $this->assertFalse(file_exists($homes['notVisible'].'/.mcxPikkumies/pikkumies/bin/pikkumies.ran'), 'Pikkumies is never run where its windows do not run');
    }
    public function testConnectShowsThePermanentAddressOnlyWithAServiceId(): void
    {
        $html = $this->runWelcomeHome('$_SERVER["HTTP_HOST"] = "host.example.test"; $apps = array("apps" => array());'
            .'echo json_encode(array("with" => pmssWelcomeHomeConnectHtmlBuild("alice", $apps, false, 12345),'
            .' "none" => pmssWelcomeHomeConnectHtmlBuild("alice", $apps, false, 0),'
            .' "omitted" => pmssWelcomeHomeConnectHtmlBuild("alice", $apps, false)));');
        // The same name nginx serves and createWebPublicCerts prints (ADR 0040).
        $name = \pmssMcxLabel('service', '12345').'.mcx.fi';
        $this->assertStringContainsAllStrings(['<dt>Permanent address</dt><dd>'.$name.'</dd>', 'data-copy="'.$name.'"',
            'index.php/Permalinks_on_PMSS', '<dt>Host</dt><dd>host.example.test</dd>'], $html['with']);
        foreach (['none', 'omitted'] as $case) {
            $this->pmssAssertStringNotContainsString('Permanent address', $html[$case], $case);
            $this->pmssAssertStringNotContainsString('mcx.fi', $html[$case], $case);
            $this->assertStringContainsString('<dt>Username</dt><dd>alice</dd>', $html[$case], $case);
        }
    }
}
