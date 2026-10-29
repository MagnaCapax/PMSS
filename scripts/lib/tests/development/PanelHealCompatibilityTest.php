<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Billing's heal delivery is independent of the node's nightly panel update. */
final class PanelHealCompatibilityTest extends TestCase
{
    private const FILES = ['deluge', 'index', 'info', 'lighttpdRestart', 'qbittorrent', 'rclone',
        'rtorrentRestart', 'scriptsInc', 'scriptsIncActionJs', 'scriptsIncAppProcess', 'scriptsIncAppsHtml',
        'scriptsIncAppsStatus', 'scriptsIncBackup', 'scriptsIncBonus', 'scriptsIncCgroup', 'scriptsIncFiles',
        'scriptsIncFormat', 'scriptsIncFrontend', 'scriptsIncRestore', 'scriptsIncRestoreTar', 'stats',
        'storageHealthNotice', 'toggleApp', 'toggleAppCatalog', 'toggleAppRuntime', 'userMediaStackPanel',
        'userOpenClaw', 'userPasswords', 'userTrafficLimit', 'webCgroupMemoryStatus', 'welcome',
        'welcomeAnnouncements', 'welcomeMessage'];

    public function testEveryHealIncludeOfANightlyPhpFileIsGuardedByIsFile(): void
    {
        foreach (self::FILES as $name) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$name.'.php');
            $offset = 0;
            foreach (token_get_all($source) as $token) {
                $text = is_array($token) ? $token[1] : $token;
                if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                    $expression = substr($source, $offset, strpos($source, ';', $offset) - $offset);
                    if (preg_match('~__DIR__\s*\.\s*[\'\"]/([^\'\"]+\.php)[\'\"]~', $expression, $match)) {
                        if (!in_array(substr($match[1], 0, -4), self::FILES, true)) {
                            $before = substr($source, 0, $offset);
                            $guard = '~if\s*\(is_file\(__DIR__\s*\.\s*[\'\"]/'
                                .preg_quote($match[1], '~').'[\'\"]\)\)\s*(?:\{\s*)?$~';
                            $this->assertSame(1, preg_match($guard, $before), $name.' requires nightly '.$match[1].' without a guard');
                        }
                    } else {
                        // Dynamic loaders have explicit path guards; keep this inventory closed.
                        $dynamic = ['scriptsInc' => ['is_file($p)', '$p'],
                            'scriptsIncFiles' => ['is_file($path)', '$path'],
                            'userOpenClaw' => ['!is_file($installer)', '$installer'],
                            'info' => [null, '$'], 'welcome' => [null, '$path']];
                        $this->assertTrue(isset($dynamic[$name]), 'Unreviewed dynamic heal include: '.$name);
                        list($guard, $variable) = $dynamic[$name];
                        $this->assertStringContainsString($variable, $expression);
                        if ($guard !== null) $this->assertStringContainsString($guard, substr($source, 0, $offset));
                        // info only includes healed storageHealthNotice/stats; welcome reads operator network data.
                    }
                }
                $offset += strlen($text);
            }
        }
    }

    public function testHistoricalHealFunctionsRemainDefinedInTheHealSet(): void
    {
        $functions = [];
        foreach (self::FILES as $name) {
            $source = $this->pmssReadRepoFile('etc/skel/www/'.$name.'.php');
            preg_match_all('/\bfunction\s+(pmss[A-Za-z0-9_]+)\s*\(/', $source, $matches);
            foreach ($matches[1] as $function) $functions[$function][] = $name;
        }
        foreach (['pmssCustomerNativeStop', 'pmssCustomerTorrentClientToggleStart',
            'pmssQbittorrentFrontendPasswordSync', 'pmssQbittorrentFrontendPasswordApply',
            'pmssQbittorrentFrontendPasswordHash', 'pmssQbittorrentFrontendPreferenceSet'] as $function) {
            $this->assertSame(1, count($functions[$function] ?? []), $function.' must have exactly one healed definition');
        }
    }

    public function testHealedToggleKeepsTheLegacyStartWithMissingOrOldNightlyHelpers(): void
    {
        foreach ([false, true] as $oldHelper) {
            $home = $this->pmssMakeTempDir('pmss-heal-toggle-');
            $file = $this->pmssWriteFile($home.'/www/scriptsIncAppProcess.php',
                $this->pmssReadRepoFile('etc/skel/www/scriptsIncAppProcess.php'));
            if ($oldHelper) $this->pmssWriteFile($home.'/www/configureTorrentClients.php', '<?php /* Old switch has no protected-start helpers. */');
            $script = 'require '.var_export($file, true).'; $calls=0;'
                .'$ok=pmssCustomerTorrentClientToggleStart("qbittorrent",function()use(&$calls){$calls++;},'
                .var_export($home, true).'); echo json_encode([$ok,$calls]);';
            $result = $this->pmssExecInlinePhp($script);
            $this->assertSame(0, $result['rc'], $result['output']);
            $this->assertSame('[true,1]', $result['output']);
        }
    }

    public function testHealedEndpointKeepsPasswordHelpersAndAcceptsAnOldVoidToggle(): void
    {
        $home = $this->pmssMakeTempDir('pmss-heal-endpoint-');
        $endpoint = $this->pmssWriteFile($home.'/www/qbittorrent.php', $this->pmssReadRepoFile('etc/skel/www/qbittorrent.php'));
        $this->pmssWriteFile($home.'/www/scriptsInc.php', <<<'PHP'
<?php
function pmssFrontendActionRequest() { return 'start'; }
function pmssFrontendPostActionRequired() {}
function pmssCustomerTorrentClientToggleStart() {}
function pmssFrontendToggleAction($file,$start,$stop) { $start(); }
PHP
        );
        $script = 'require '.var_export($endpoint, true).'; echo (http_response_code() ?: 200)."\\n";'
            .'echo pmssQbittorrentFrontendPreferenceSet("[Preferences]\\r\\nWebUI\\\\Port=8080\\r\\n", "WebUI\\\\Port", "23456");';
        $result = $this->pmssExecInlinePhp($script);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame("200\n[Preferences]\nWebUI\\Port=23456", $result['output']);
    }

    public function testNightlyPasswordHelperDoesNotAssumeNewHealDefinitionsAreLoaded(): void
    {
        $script = 'require '.var_export($this->pmssRepoPath('etc/skel/www/qbittorrentPassword.php'), true).';'
            .'echo json_encode(pmssQbittorrentFrontendPasswordApplyStopped("/must-not-be-read", "separate-secret"));';
        $result = $this->pmssExecInlinePhp($script);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('false', $result['output']);
    }
}
