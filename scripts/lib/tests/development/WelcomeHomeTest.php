<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Welcome home view helpers (ADR 0147, 0148): news selection, status chips and the app tiles. */
final class WelcomeHomeTest extends TestCase
{
    /**
     * Run PHP against welcome.php's helper tail in a child process and decode its JSON answer.
     * Everything from pmssWelcomeTrafficUpgradeUrlBuild() on is function definitions only.
     */
    private function runWelcomeHome(string $script, bool $offline = false): array
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

    public function testNewsSelectKeepsFourAnnouncementsAndTenArticlesNewestFirst(): void
    {
        $news = $this->newsSelect($this->newsItems('A', 5, 100), array_reverse($this->newsItems('B', 12, 200)));

        $this->assertSame(['A1', 'A2', 'A3', 'A4'], array_column($news['announcements'], 'title'));
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

    public function testSuggestionsSkipQbittorrentNextToFeaturedApp(): void
    {
        $result = $this->runWelcomeHome('echo json_encode(array('
            .'"hero" => pmssWelcomeHomeSuggestions(array(array("id" => "openclaw", "hero" => true))),'
            .'"plain" => pmssWelcomeHomeSuggestions(array(array("id" => "openclaw", "hero" => false)))'
            .'));');

        $hero = array_column($result['hero'], 'id');
        $this->assertSame(array('jellyfin', 'rclone', 'sonarr'), $hero);
        $this->assertFalse(in_array('qBittorrent', $hero, true), 'An OpenClaw-first account is not nudged back to a torrent client');
        $this->assertSame(array('jellyfin', 'qBittorrent'), array_column($result['plain'], 'id'));
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
}
