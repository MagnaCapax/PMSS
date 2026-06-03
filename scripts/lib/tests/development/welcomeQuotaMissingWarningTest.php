<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class welcomeQuotaMissingWarningTest extends TestCase
{
    private function makeWelcomeSafetyFixture(): string
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomePageStateBuild');
        $end = strpos($source, 'function pmssWelcomeVendorRead');
        $this->assertTrue($start !== false, 'welcome.php page-state helpers should remain present');
        $this->assertTrue($end !== false && $end > $start, 'welcome.php vendor reader should follow safety helpers');

        $dir = $this->pmssMakeTempDir('pmss-welcome-safety-');
        $www = $dir.'/www';
        $this->pmssEnsureDir($www);
        $this->pmssWriteFile($www.'/scriptsInc.php', $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php'));
        $fixture = $www.'/welcomeSafety.php';
        return $this->pmssWriteFile($fixture, "<?php\nrequire_once __DIR__.'/scriptsInc.php';\n".substr($source, $start, $end - $start));
    }

    private function runWelcomeSafetyScript(string $script): string
    {
        $fixture = $this->makeWelcomeSafetyFixture();
        return $this->pmssRunInlinePhp('require '.var_export($fixture, true).'; '.$script);
    }

    private function runWelcomePageStateBonusQuota(string $home): int
    {
        $fixture = $this->makeWelcomeSafetyFixture();
        $output = $this->pmssRunInlinePhp(
            'require '.var_export($fixture, true).';'
            .'if (!function_exists("pmssWelcomeVendorRead")) { function pmssWelcomeVendorRead() { return array("name" => "Pulsed Media"); } }'
            .'if (!function_exists("pmssWelcomeContextualMessageBuild")) { function pmssWelcomeContextualMessageBuild($quotaInfo) { return ""; } }'
            .'if (!function_exists("pmssWelcomeDelugeStateBuild")) { function pmssWelcomeDelugeStateBuild($username, $path) { return array("canRotate" => false, "passwordNotice" => "", "password" => ""); } }'
            .'if (!function_exists("pmssWelcomeUserConfigNumber")) { function pmssWelcomeUserConfigNumber($key, $allowSymlink = false) { return null; } }'
            .'chdir('.var_export($home, true).');'
            .'$state = pmssWelcomePageStateBuild();'
            .'echo json_encode(array("bonusQuota" => $state["bonusQuota"]));',
            [],
            '2>&1'
        );
        $decoded = $this->pmssDecodeJsonArray($output);
        $this->assertTrue(is_int($decoded['bonusQuota'] ?? null), 'Expected bonusQuota JSON integer, got: '.$output);
        return $decoded['bonusQuota'];
    }

    private function makeWelcomeUsageFixture(): string
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $start = strpos($source, 'function pmssWelcomeTrafficEffectiveHtmlBuild');
        $this->assertTrue($start !== false, 'welcome.php usage helpers should remain present');

        $tail = substr($source, $start);
        $tail = preg_replace('/\?>\s*$/', '', $tail);
        $fixture = $this->pmssMakeTempPath('pmss-welcome-usage-', '.php');
        $memoryHelper = $this->pmssRepoPath('etc/skel/www/webCgroupMemoryStatus.php');
        return $this->pmssWriteFile(
            $fixture,
            "<?php\n"
            ."if (!function_exists('pmssReadSerializedArrayFile')) { function pmssReadSerializedArrayFile(\$path) { \$raw = @file_get_contents(\$path); if (!is_string(\$raw)) return null; \$data = @unserialize(\$raw, array('allowed_classes' => false)); return is_array(\$data) ? \$data : null; } }\n"
            .'require_once '.var_export($memoryHelper, true).";\n"
            .$tail
        );
    }

    private function runWelcomeUsageScript(string $script, string $stderrRedirect = '2>/dev/null'): string
    {
        $fixture = $this->makeWelcomeUsageFixture();
        return $this->pmssRunInlinePhp('require '.var_export($fixture, true).'; '.$script, [], $stderrRedirect);
    }

    public function testQuotaMissingWarningGuardUsesOnlyQuotaLimitFields(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('if ($hardLimit == 0 || $totalSpace == 0)', $source);
        $this->pmssAssertStringNotContainsString('$freeSpace == 0', $source);
        $this->pmssAssertStringNotContainsString('|| $usedBytes == 0', $source);
    }

    public function testWelcomePageLabelsDelugeWebUiPasswordClearly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('Deluge Web UI password:', $source);
        $this->pmssAssertStringNotContainsString('Deluge password: <b>', $source);
    }

    public function testWelcomePageUsesCustomerDelugePasswordRotationHelper(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('pmssDelugeServicePasswordRotate((string) $username)', $source);
        $this->pmssAssertStringNotContainsString('pmssDelugeAuthWriteLocalclientPassword($delugeAuthPath, $newDelugePassword)', $source);
    }

    public function testWelcomeVendorReaderUsesSafeSerializedArrayDecoder(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('pmssWelcomeSerializedArrayDecode($vendor, 4096)', $source);
        $this->pmssAssertStringNotContainsString('$vendor = @unserialize($vendor)', $source);
    }

    public function testWelcomeMemoryProbeBuildsUidCommandWithoutShellSubstitution(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/webCgroupMemoryStatus.php');

        $this->assertStringContainsString("'systemctl show user-'.\$uid.'.slice -p MemoryCurrent --value 2>/dev/null'", $source);
        $this->pmssAssertStringNotContainsString("systemctl show user-$('/usr/bin/id' -u).slice", $source);
        $this->pmssAssertRepoFileNotContainsString('etc/skel/www/welcome.php', 'function '.'memory'.'CreateSection(');
    }

    public function testWelcomeServiceActionButtonSnapshots(): void
    {
        if (!function_exists('pmssWelcomeActionButtonHtmlBuild')) {
            $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
            $start = strpos($source, 'function pmssWelcomeHtmlAttr'); $end = strpos($source, 'function pmssWelcomeHeadingHtmlBuild');
            $this->assertTrue($start !== false && $end !== false && $end > $start, 'welcome.php service-control helpers should remain present');

            $fixture = $this->pmssMakeTempPath('pmss-welcome-service-controls-', '.php');
            require_once $this->pmssWriteFile($fixture, "<?php\n".substr($source, $start, $end - $start));
        }

        /** @var callable(mixed,mixed,mixed,mixed,mixed,mixed): string $button */
        $button = 'pmssWelcomeActionButtonHtmlBuild';

        $this->assertSame(
            array('start' => 'a20fd8acbdd11bfdc8870e2e7f12e958949003b7683bcbe96c8a6790d2910f6b', 'restart' => '258044f7ee96acc6e493dd7aaf01116d4491c1798ee95c2b2b00a12b8e46c65c', 'quote' => 'e684ca17b182836eeee3702faad7b7fab6a0fa18a9cfc9e123e6dcd87ab6668c'),
            array('start' => hash('sha256', $button('rcloneStart', 'Start Rclone', 'rclone.php?action=start', 'Rclone starting, access at /user-USERNAME/rclone. Refresh GUI to see tab.', true, 'Starting Rclone...')), 'restart' => hash('sha256', $button('qbittorrentRestart', 'Restart qBittorrent', 'qbittorrent.php?action=restart', 'qBittorrent restart requested.', false, 'Restarting qBittorrent...')), 'quote' => hash('sha256', $button('demo', "Don't", 'demo.php?action=run', "It's ready", true, "Line\nnext")))
        );
    }

    public function testWelcomeRemoteFetchRejectsUnexpectedEndpoints(): void
    {
        $output = $this->runWelcomeUsageScript(
            'echo json_encode(array('
            .'"http" => pmssWelcomeRemoteFetch("http://pulsedmedia.com/clients/announcementsrss.php") === false,'
            .'"otherHost" => pmssWelcomeRemoteFetch("https://example.invalid/clients/announcementsrss.php") === false,'
            .'"otherPath" => pmssWelcomeRemoteFetch("https://pulsedmedia.com/remote/other.php") === false,'
            .'"credentials" => pmssWelcomeRemoteFetch("https://user:p@pulsedmedia.com/clients/announcementsrss.php") === false,'
            .'"control" => pmssWelcomeRemoteFetch("https://pulsedmedia.com/clients/announcementsrss.php'."\n".'") === false'
            .'));'
        );

        $this->assertSame(
            array(
                'http' => true,
                'otherHost' => true,
                'otherPath' => true,
                'credentials' => true,
                'control' => true,
            ),
            $this->pmssDecodeJsonArray($output)
        );

        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
        $this->assertStringContainsString("'https://pulsedmedia.com/remote/welcomeHeadingText.php'", $source);
        $this->assertStringContainsString("'https://pulsedmedia.com/clients/announcementsrss.php'", $source);
        $this->assertStringContainsString('@file_get_contents($url, false, pmssWelcomeHttpContextCreate(), 0, 1048576)', $source);
    }

    public function testWelcomeLocalHelperRejectsTraversalPaths(): void
    {
        $fixture = $this->makeWelcomeSafetyFixture();
        $this->pmssWriteFile(dirname($fixture).'/safeHelper.php', "<?php\nfunction pmssWelcomeSafeHelperLoaded() { return true; }\n");

        $output = $this->pmssRunInlinePhp(
            'require '.var_export($fixture, true).';'
            .'echo json_encode(array('
            .'"safe" => pmssWelcomeRequireLocalHelper("safeHelper.php"),'
            .'"loaded" => function_exists("pmssWelcomeSafeHelperLoaded"),'
            .'"traversal" => pmssWelcomeRequireLocalHelper("../safeHelper.php"),'
            .'"absolute" => pmssWelcomeRequireLocalHelper("/tmp/safeHelper.php")'
            .'));'
        );

        $this->assertSame(
            array('safe' => true, 'loaded' => true, 'traversal' => false, 'absolute' => false),
            $this->pmssDecodeJsonArray($output)
        );
    }

    public function testWelcomeQuotaReaderAcceptsSerializedArrayContract(): void
    {
        $payload = array('hardLimit' => 200, 'totalSpace' => 100, 'usedBytes' => 25);
        $output = $this->runWelcomeSafetyScript(
            '$_GET["quota"] = urlencode(serialize('.var_export($payload, true).'));'
            .'echo json_encode(pmssWelcomeQuotaInfoRead());'
        );

        $this->assertSame($payload, $this->pmssDecodeJsonArray($output));
    }

    public function testWelcomeQuotaReaderRejectsObjectPayloads(): void
    {
        $output = $this->runWelcomeSafetyScript(
            '$_GET["quota"] = urlencode(serialize(array("hardLimit" => new stdClass())));'
            .'echo json_encode(pmssWelcomeQuotaInfoRead());'
        );

        $this->assertSame(array(), $this->pmssDecodeJsonArray($output));
    }

    public function testWelcomeQuotaReaderRejectsNonScalarAndOversizeInput(): void
    {
        $arrayOutput = $this->runWelcomeSafetyScript(
            '$_GET["quota"] = array("not-scalar");'
            .'echo json_encode(pmssWelcomeQuotaInfoRead());'
        );
        $largeOutput = $this->runWelcomeSafetyScript(
            '$_GET["quota"] = str_repeat("x", 8193);'
            .'echo json_encode(pmssWelcomeQuotaInfoRead());'
        );

        $this->assertSame(array(), $this->pmssDecodeJsonArray($arrayOutput));
        $this->assertSame(array(), $this->pmssDecodeJsonArray($largeOutput));
    }

    public function testWelcomeQuotaReaderRejectsPathologicallyDeepInput(): void
    {
        $output = $this->runWelcomeSafetyScript(
            '$payload = array(); $cursor =& $payload;'
            .'for ($i = 0; $i < 34; $i++) { $cursor["child"] = array(); $cursor =& $cursor["child"]; }'
            .'$_GET["quota"] = urlencode(serialize($payload));'
            .'echo json_encode(pmssWelcomeQuotaInfoRead());'
        );

        $this->assertSame(array(), $this->pmssDecodeJsonArray($output));
    }

    public function testWelcomePageStateAcceptsPositiveBonusQuota(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-welcome-bonus-').'/www';
        $this->pmssWriteFile(dirname($home).'/.bonusQuota', "25\n");

        $this->assertSame(25, $this->runWelcomePageStateBonusQuota($home));
    }

    public function testWelcomePageStateRejectsNegativeBonusQuota(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-welcome-bonus-').'/www';
        $this->pmssWriteFile(dirname($home).'/.bonusQuota', "-10\n");

        $this->assertSame(0, $this->runWelcomePageStateBonusQuota($home));
    }

    public function testWelcomePageStateRejectsSymlinkedBonusQuota(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-welcome-bonus-').'/www';
        $target = $this->pmssWriteFile(dirname($home).'/.bonusQuotaTarget', "77\n");
        $this->pmssCreateSymlinkOrSkip($target, dirname($home).'/.bonusQuota');

        $this->assertSame(0, $this->runWelcomePageStateBonusQuota($home));
    }

    public function testWelcomeQuotaSectionHandlesMalformedPayloadWithoutNotice(): void
    {
        $output = $this->runWelcomeUsageScript(
            'echo quotaCreateSection(array("hardLimit" => 100, "totalSpace" => 50));',
            '2>&1'
        );

        $this->assertStringContainsString('Quota info is missing', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeTrafficSectionHandlesMalformedPayloadWithoutNotice(): void
    {
        $output = $this->runWelcomeUsageScript(
            'ob_start(); trafficCreateSection(array("raw" => array()), 100); echo ob_get_clean();',
            '2>&1'
        );

        $this->assertStringContainsString('Traffic usage data is unavailable right now.', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeTrafficSectionShowsRawUsedAndLimitLine(): void
    {
        $output = $this->runWelcomeUsageScript(
            'ob_start(); trafficCreateSection(array("raw" => array("month" => 433152)), 1000); echo ob_get_clean();',
            '2>&1'
        );

        $this->assertStringContainsString('Used: 423 GiB / Limit: 1,000 GiB (30-day window)', $output);
        $this->assertStringContainsString('Current effective: full plan port speed', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeTrafficSectionShowsApproachDisclosure(): void
    {
        $output = $this->runWelcomeUsageScript(
            'ob_start(); trafficCreateSection(array("raw" => array("month" => 716800)), 1000); echo ob_get_clean();',
            '2>&1'
        );

        $this->assertStringContainsString('Usage: <b>70.0%</b> of monthly traffic cap.', $output);
        $this->assertStringContainsString('Approaching monthly traffic cap', $output);
        $this->assertStringContainsString('Bandwidth#Graduated_throttling', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeTrafficSectionShowsActiveThrottleDisclosure(): void
    {
        $state = array('defaultCapMbit' => 100, 'effectiveCapMbit' => 25, 'isReduced' => true);
        $output = $this->runWelcomeUsageScript(
            'ob_start(); trafficCreateSection(array("raw" => array("month" => 1536000)), 1000, null, 0, '.var_export($state, true).'); echo ob_get_clean();',
            '2>&1'
        );

        $this->assertStringContainsString('Throttled to 25 Mbps', $output);
        $this->assertStringContainsString('50.0% over cap', $output);
        $this->assertStringContainsString('Throttle lift requires 3 consecutive days under cap.', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeTrafficSectionShowsCooldownDisclosure(): void
    {
        $state = array('defaultCapMbit' => 100, 'effectiveCapMbit' => 25, 'isReduced' => true, 'throttleFileMtime' => time() - 86400);
        $output = $this->runWelcomeUsageScript(
            'ob_start(); trafficCreateSection(array("raw" => array("month" => 921600)), 1000, null, 0, '.var_export($state, true).'); echo ob_get_clean();',
            '2>&1'
        );

        $this->assertStringContainsString('Throttle cooldown active', $output);
        $this->assertStringContainsString('current ceiling is 25 Mbps', $output);
        $this->assertStringContainsString('remaining under-cap cooldown: about 2 days', $output);
        $this->pmssAssertStringNotContainsString('Undefined', $output);
    }

    public function testWelcomeMemorySectionSnapshots(): void
    {
        $fixture = $this->makeWelcomeUsageFixture();
        $home = $this->pmssMakeUserWebHome('pmss-welcome-memory-', 'home').'/www';
        $output = $this->pmssRunInlinePhp(
            'require '.var_export($fixture, true).';'
            .'$home = '.var_export($home, true).';'
            .'chdir($home);'
            .'@mkdir("../.config", 0755, true);'
            .'file_put_contents("../.config/pmss-user.json", json_encode(array("ramMiB" => 1024)));'
            .'file_put_contents("../.resourceData", serialize(array("memory" => array("current" => 536870912, "anon" => 268435456, "file" => 134217728))));'
            .'$basePressure = array("available" => true, "status" => "LOW", "status_color" => "#81c784", "throttle_events" => 0, "max_events" => 0, "oom_events" => 0, "oom_kill_events" => 0, "message" => "");'
            .'$cases = array();'
            .'$cases["low"] = hash("sha256", pmssWelcomeMemorySectionHtmlBuild($basePressure));'
            .'$cases["throttled"] = hash("sha256", pmssWelcomeMemorySectionHtmlBuild(array_replace($basePressure, array("status" => "THROTTLED", "status_color" => "#d2691e", "throttle_events" => 3, "message" => "Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed."))));'
            .'$cases["oom"] = hash("sha256", pmssWelcomeMemorySectionHtmlBuild(array_replace($basePressure, array("status" => "THROTTLED", "status_color" => "#d2691e", "throttle_events" => 3, "oom_events" => 1, "message" => "Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed."))));'
            .'echo json_encode($cases);',
            [],
            '2>&1'
        );

        $this->assertSame(
            array(
                'low' => '1c8003bfe60f82fabf1a7f2c7b89bf8d5891016abfe3470df1eef1d47c2d0dff',
                'throttled' => '8be9eb9abd053de1551f21aa0d5c8d6c7915019dad40d7b2c17f27c836cd031e',
                'oom' => '84fb923ea3b0cf5eaa347d3a15955987c30be39eedec0086debb1ef1b1623876',
            ),
            $this->pmssDecodeJsonArray($output)
        );
    }

    public function testWelcomeGaugeHtmlAndColorSnapshot(): void
    {
        if (!function_exists('createGauge')) {
            $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');
            $start = strpos($source, 'function createStackedGauge');
            $this->assertTrue($start !== false, 'welcome.php gauge helpers should remain present');

            $tail = substr($source, $start);
            $tail = preg_replace('/\?>\s*$/', '', $tail);
            $fixture = $this->pmssMakeTempPath('pmss-welcome-gauge-', '.php');
            require_once $this->pmssWriteFile($fixture, "<?php\n".$tail);
        }

        $this->assertSame(
            array(
                'single' => '85016bc86aa6155a5775e00ffba371c7663ba1270101e66c012b34dbdadfed9e',
                'stacked' => 'e0d82eedbaa584ada4e7a80b626b3adef78dbb415f090899de45ecb662f18293',
                'stacked_clamped' => '9ef71d1f87189eb5cd0d6eac66428ddefa4d8cdda5af7fdc5d5ff4da42146ff0',
                'single_infinite' => '8121f04f49056c45452dfd7517263f5b257e118b4a1d184b56db6c4759a94337',
                'colors' => array('-10' => '90ee99', '0' => '99e699', '50' => 'c4bf99', '100' => 'ee9999', '101' => 'FF4040'),
            ),
            array(
                'single' => hash('sha256', $this->createGauge('Used / Limit', 'Used / Limit<br />Bonus', 75, 90)),
                'stacked' => hash('sha256', $this->createStackedGauge(
                    'Process: 1 | Cache: 2',
                    'Process: 1 | Cache: 2',
                    66.7,
                    array(
                        array('width' => 33.3, 'color' => '#aabbcc'),
                        array('width' => 22.2, 'color' => '#ddeeff'),
                        array('width' => 44.5, 'color' => 'transparent'),
                    )
                )),
                'stacked_clamped' => hash('sha256', $this->createStackedGauge(
                    'Wide',
                    'Wide',
                    125,
                    array(
                        array('width' => 80, 'color' => '#111111'),
                        array('width' => 80, 'color' => '#222222'),
                    )
                )),
                'single_infinite' => hash('sha256', $this->createGauge('Infinite', 'Infinite', INF, INF)),
                'colors' => array('-10' => $this->gaugeColor(-10), '0' => $this->gaugeColor(0), '50' => $this->gaugeColor(50), '100' => $this->gaugeColor(100), '101' => $this->gaugeColor(101)),
            )
        );
    }

    private function createGauge($titleText, $footerText, $percent, $percentMax = 0): string
    {
        /** @var callable(mixed,mixed,mixed,mixed): string $builder */
        $builder = 'createGauge';
        return $builder($titleText, $footerText, $percent, $percentMax);
    }

    private function createStackedGauge($titleText, $footerText, $percent, array $segments): string
    {
        /** @var callable(mixed,mixed,mixed,array<int,array<string,mixed>>): string $builder */
        $builder = 'createStackedGauge';
        return $builder($titleText, $footerText, $percent, $segments);
    }

    private function gaugeColor($percent): string
    {
        /** @var callable(mixed): string $color */
        $color = 'gaugeColor';
        return $color($percent);
    }

}
