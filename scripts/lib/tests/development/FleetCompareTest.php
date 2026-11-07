<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Welcome "Disk wait" card and the fleetCompare.php queue script (ADR 0227). */
final class FleetCompareTest extends TestCase
{
    private const NOW = 1793700000;

    /** Run pmssFleetCompareArgsParse in a child process (the script pulls in the root-side writer libraries). */
    private function scriptParse(array $args): ?array
    {
        $code = 'define("PMSS_TEST_MODE", true); require '.var_export($this->pmssRepoPath('scripts/util/remote/fleetCompare.php'), true)
            .'; echo json_encode(pmssFleetCompareArgsParse('.var_export($args, true).'), JSON_PRESERVE_ZERO_FRACTION);';
        $out = $this->pmssRunInlinePhp($code, [], '2>&1');
        $this->assertStringNotContainsString('Warning', $out);
        return json_decode($out, true);
    }

    public function testScriptAcceptsOnlyATierNamePlainNumbersAndATenDigitTimestamp(): void
    {
        $this->assertSame(['tier' => 'M1000-SSD', 'psi' => 14.2, 'svcMs' => 31.0, 'ts' => 1793681379], $this->scriptParse(['M1000-SSD', '14.20', '31', '1793681379']));
        $this->assertSame(['tier' => null, 'psi' => 0.0, 'svcMs' => 4.25, 'ts' => 1793681379], $this->scriptParse(['-', '0', '4.25', '1793681379']));
        // ADR 0237: an optional fifth number, the service group's median read latency.
        $this->assertSame(['tier' => 'M1000-SSD', 'psi' => 1.0, 'svcMs' => 2.0, 'ts' => 1793681379, 'groupIopingMs' => 0.24], $this->scriptParse(['M1000-SSD', '1', '2', '1793681379', '0.24']));
        foreach ([
            ['M1000', '1', '2'], ['M1000', '1', '2', '1793681379', '6', '7'], ['M1000', '1', '2', '1793681379', '-1'],
            ['M1000', '1', '2', '1793681379', '0.123'], ['M1000', '1', '2', '1793681379', ''], ['M1000', '1', '2', '1793681379', '1;id'], ['M1000', '1.234', '2', '1793681379'], ['M1000', '1234567', '2', '1793681379'],
            ['M1000', '-1', '2', '1793681379'], ['M1000', '1e3', '2', '1793681379'], ['M1000', '1;id', '2', '1793681379'], ['M1000', "1\n", '2', '1793681379'],
            ['M1000', '1', '', '1793681379'], ['M1000', '1', '2', '179368137'], ['M1000', '1', '2', '17936813790'], ['M1000', '1', '2', '1793681379.5'],
            ['', '1', '2', '1793681379'], ['a;touch', '1', '2', '1793681379'], ['$(id)', '1', '2', '1793681379'], ['M 1000', '1', '2', '1793681379'],
            ["M1000\n", '1', '2', '1793681379'], ['abcdefghijklmnopq', '1', '2', '1793681379'], ['../x', '1', '2', '1793681379'],
        ] as $args) {
            $this->assertSame(null, $this->scriptParse($args), json_encode($args));
        }
    }

    private function feed(array $tiers, array $fleet, int $generated): string
    {
        return json_encode(['generated_at' => gmdate('c', $generated), 'fleet' => $fleet, 'tiers' => $tiers]);
    }

    private function row(float $psi, float $svc, string $status = 'reporting'): array
    {
        return ['status' => $status, 'avg_psi_io_full' => $psi, 'avg_service_time_ms' => $svc];
    }

    /** Render the card through the real helper tail of welcome.php, with the feed in an account cache file. */
    private function cardFor($own, ?string $feed, bool $ownRaw = false, int $serviceId = 0): string
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeTrafficUpgradeUrlBuild');
        $this->assertTrue($start !== false, 'welcome.php view helpers should remain present');
        $dir = $this->pmssMakeTempDir('pmss-fleet-compare-');
        $fixture = $this->pmssWriteFile($dir.'/welcome.php', "<?php\nrequire_once ".var_export($this->pmssRepoPath('etc/skel/www/scriptsInc.php'), true).";\n"
            .preg_replace('/\?>\s*$/', '', substr($source, $start)));
        $ownFile = $dir.'/fleetCompare.json';
        if ($own !== null) $this->pmssWriteFile($ownFile, $ownRaw ? $own : json_encode($own));
        if ($feed !== null) {
            $this->pmssWriteFile($dir.'/home/.cache/pmss-welcome/'.substr(sha1('https://pulsedmedia.com/data/v1/fleet-performance.json'), 0, 16).'.cache', $feed);
        }
        $out = $this->pmssRunInlinePhpRequire($fixture, 'echo json_encode(pmssWelcomeHomeFleetMetricHtmlBuild('.var_export($dir.'/home', true).', '.var_export($ownFile, true).', '.self::NOW.', '.$serviceId.'));', [], '2>&1');
        $this->assertStringNotContainsString('Warning', $out);
        $this->assertStringNotContainsString('Notice', $out);
        return (string) json_decode($out, true);
    }

    public function testCardLinksTheClientAreaServicePageAndStatesNoAmounts(): void
    {
        $card = $this->cardFor($this->own(), $this->goodFeed(), false, 4242);
        $this->assertStringContainsString('href="https://pulsedmedia.com/clients/clientarea.php?action=productdetails&amp;id=4242"', $card);
        $this->assertStringContainsString('Want another server? See your options', $card);
        $this->assertSame(0, preg_match('/\b\d+ days?\b|bonus|free/i', strip_tags($card)));
        $this->assertStringNotContainsString('clientarea.php', $this->cardFor($this->own(), $this->goodFeed()));
    }

    private function own(array $over = []): array
    {
        return $over + ['tier' => 'M1000', 'psi' => 14.2, 'svcMs' => 31.0, 'ts' => self::NOW - 3600];
    }

    private function goodFeed(): string
    {
        return $this->feed(['M1000' => $this->row(22.8, 43.0), 'V1000' => $this->row(5.0, 9.0)], $this->row(10.0, 20.0), self::NOW - 1800);
    }

    public function testCardShowsExactNumbersAndATickAtTheTierAverage(): void
    {
        $below = $this->cardFor($this->own(), $this->goodFeed());
        $this->assertStringContainsString('Disk wait', $below);
        $this->assertStringContainsString('lower is better', $below);
        $this->assertStringContainsString('14.2%', $below);
        $this->assertStringContainsString('Fleet: 22.8%<br>Response 31.0 ms · fleet 43.0 ms', $below);
        $this->assertStringContainsString('class="h-tick" style="left:50%"', $below);
        $this->assertStringContainsString('class="s-base" style="width:31.14%"', $below);
        $this->assertStringContainsString('on the same plan family', $below);
        $this->assertStringContainsString('width:65.79%', $this->cardFor($this->own(['psi' => 30.0]), $this->goodFeed()));
        $this->assertStringContainsString('width:100%', $this->cardFor($this->own(['psi' => 90.0]), $this->goodFeed()));
        $this->assertStringContainsString('Response 30.6 ms', $this->cardFor($this->own(['svcMs' => 30.64]), $this->goodFeed()));
        foreach (['worse', 'overloaded', 'slow', 'bad'] as $word) $this->assertStringNotContainsString($word, strtolower($below));
    }

    public function testNoPublicTierOrANonReportingTierUsesTheFleetWideAverage(): void
    {
        foreach ([$this->own(['tier' => null]), $this->own(['tier' => 'Unknown-Tier']), $this->own(['tier' => 'Dragon-R']), $this->own(['tier' => '../x'])] as $own) {
            $feed = $this->feed(['Dragon-R' => $this->row(1.0, 1.0, 'no_recent_data')], ['status' => 'reporting', 'avg_psi_io_full' => 10.0, 'avg_service_time_ms' => 20.0], self::NOW - 600);
            $html = $this->cardFor($own, $feed);
            $this->assertStringContainsString('Fleet: 10.0%<br>Response 31.0 ms · fleet 20.0 ms', $html);
            $this->assertStringContainsString('across the fleet', $html);
        }
        $nullPsi = $this->feed(['M1000' => ['status' => 'reporting', 'avg_psi_io_full' => null, 'avg_service_time_ms' => 43.0]], ['status' => 'reporting', 'avg_psi_io_full' => 10.0, 'avg_service_time_ms' => 20.0], self::NOW - 600);
        $this->assertStringContainsString('Fleet: 10.0%', $this->cardFor($this->own(), $nullPsi));
    }

    public function testCardIsAbsentWhenAnyInputIsMissingStaleOrInvalid(): void
    {
        $feed = $this->goodFeed();
        $this->assertStringContainsString('Disk wait', $this->cardFor($this->own(['ts' => self::NOW - 48 * 3600]), $feed));
        foreach ([
            [null, $feed], [$this->own(['ts' => self::NOW - 48 * 3600 - 1]), $feed], [$this->own(['ts' => self::NOW + 7200]), $feed],
            [$this->own(['psi' => 'abc']), $feed], [$this->own(['psi' => '14.2']), $feed], [$this->own(['svcMs' => -1]), $feed],
            [$this->own(), null], [$this->own(), '{"fleet":'], [$this->own(), '[]'],
            [$this->own(), $this->feed(['M1000' => $this->row(22.8, 43.0)], ['status' => 'reporting', 'avg_psi_io_full' => 10.0, 'avg_service_time_ms' => 20.0], self::NOW - 48 * 3600 - 1)],
            [$this->own(), $this->feed(['M1000' => $this->row(0.0, 43.0)], ['status' => 'no_recent_data'], self::NOW - 60)],
            [$this->own(), $this->feed(['M1000' => ['status' => 'reporting', 'avg_psi_io_full' => 'x', 'avg_service_time_ms' => 43.0]], [], self::NOW - 60)],
        ] as $case) {
            $this->assertSame('', $this->cardFor($case[0], $case[1]), json_encode($case));
        }
        $broken = $this->own();
        unset($broken['svcMs']);
        $this->assertSame('', $this->cardFor($broken, $feed));
        $this->assertSame('', $this->cardFor('{"psi": 14.2,', $feed, true));
        $this->assertSame('', $this->cardFor('[]', $feed, true));
    }

    public function testFleetFeedPathIsPinnedAndRefreshedWithTheNewsRequest(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $this->assertStringContainsString("'/data/v1/fleet-performance.json',\n    );", $source);
        $emit = substr($source, strpos($source, 'function pmssWelcomeHomeNewsEmit'), 700);
        $this->assertStringContainsString("fleet-performance.json', 900, true)", $emit);
    }
}
