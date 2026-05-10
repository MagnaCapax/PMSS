<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Characterization coverage for the public per-user update entrypoint.
 *
 * These tests execute `pmssUpdateUserEnvironment()` in a subprocess so they can
 * isolate stubbed command runners without altering the shared in-process test
 * environment used by the rest of the development suite.
 */
class UserUpdateEnvironmentCharacterizationTest extends TestCase
{
    public function testUpdateUserEnvironmentReturnsEarlyWhenContextIsInvalid(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $script = $this->buildUserEnvironmentScript(
            'invalid',
            <<<'PHP'
@mkdir($base, 0755, true);
putenv('PMSS_HOME_DIR='.$base.'/home');
putenv('PMSS_SKEL_DIR='.$base.'/skel');
PHP
            ,
            "pmssUpdateUserEnvironment('missing-user', 'sha123');",
            <<<'PHP'
[
    'steps' => $GLOBALS['PMSS_USER_STEP_CALLS'],
    'linger' => $GLOBALS['PMSS_LINGER_USERS'],
    'output' => $output,
]
PHP
        );

        $result = $this->pmssRunInlinePhpJson(str_replace('__REPO_ROOT__', var_export($repoRoot, true), $script), ['PMSS_TEST_MODE' => '1']);

        $this->assertEquals([], $result['steps']);
        $this->assertEquals([], $result['linger']);
        $this->assertEquals('', $result['output']);
    }

    public function testUpdateUserEnvironmentRunsStablePhasesWithoutLingerHook(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $script = $this->buildUserEnvironmentScript(
            'valid',
            <<<'PHP'
$homeRoot = $base.'/home';
$skelRoot = $base.'/skel';
$user = 'alice';
$home = $homeRoot.'/'.$user;

@mkdir($home.'/data', 0755, true);
@mkdir($home.'/.lighttpd', 0755, true);
@mkdir($home.'/.config/qBittorrent', 0755, true);
@mkdir($home.'/www/rutorrent/php', 0755, true);
@mkdir($home.'/www/rutorrent/plugins/hddquota', 0755, true);
@mkdir($home.'/www/rutorrent/plugins/rss', 0755, true);
@mkdir($home.'/www/rutorrent/plugins/theme/themes', 0755, true);
@mkdir($home.'/www/rutorrent/share/users/'.$user.'/settings', 0755, true);
@mkdir($home.'/www/rutorrent/share/settings', 0755, true);
@mkdir($skelRoot.'/.irssi', 0755, true);
@mkdir($skelRoot.'/www', 0755, true);
@mkdir($skelRoot.'/www/rutorrent/plugins/hddquota', 0755, true);

file_put_contents($home.'/.rtorrent.rc', "dummy\n");
file_put_contents($home.'/.lighttpd/php.ini', "display_errors = On\n");
file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[BitTorrent]\nSession\\DiskCacheSize=16384\nSession\\MaxConnections=9999\n\n[Preferences]\nWebUI\\CSRFProtection=true\nWebUI\\ClickjackingProtection=true\nWebUI\\HostHeaderValidation=true\nDownloads\\DiskWriteCacheSize=16384\nBittorrent\\MaxConnecs=9999\n");
file_put_contents($home.'/www/filemanager.php', "before\n        ob_flush();\nafter\n");
file_put_contents($home.'/www/rutorrent/php/settings.php', '((integer)($tm["minutes"]/$interval))*$interval+$interval,');
file_put_contents($home.'/www/rutorrent/plugins/rss/action.php', "before\nob_flush();\nafter\n");
file_put_contents($skelRoot.'/www/deluge.php', "<?php\nfunction startDeluge() {\n    shell_exec('nohup python3 /home/\$(whoami)/.delugePort.py; deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');\n}\n");
file_put_contents($skelRoot.'/www/qbittorrent.php', "<?php\nfunction startQbittorrent() {\n    passthru('python3 /home/\$(whoami)/.qbittorrentPort.py; zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');\n}\n");
file_put_contents($skelRoot.'/www/rutorrent/plugins/hddquota/action.php', "return \$field;\n");
file_put_contents($skelRoot.'/.irssi/config', "test\n");
file_put_contents($skelRoot.'/www/rutorrent/plugins/hddquota/sample.txt', "quota\n");

putenv('PMSS_HOME_DIR='.$homeRoot);
putenv('PMSS_SKEL_DIR='.$skelRoot);
PHP
            ,
            <<<'PHP'
pmssUpdateUserEnvironment(
    $user,
    ''
);
PHP
            ,
            <<<'PHP'
[
    'descriptions' => array_values(array_map(static function (array $entry): string {
        return $entry['description'];
    }, $GLOBALS['PMSS_USER_STEP_CALLS'])),
    'linger' => $GLOBALS['PMSS_LINGER_USERS'],
    'output' => $output,
    'filemanager' => (string) file_get_contents($home.'/www/filemanager.php'),
    'deluge' => (string) file_get_contents($home.'/www/deluge.php'),
    'qbittorrent_frontend' => (string) file_get_contents($home.'/www/qbittorrent.php'),
    'settings' => (string) file_get_contents($home.'/www/rutorrent/php/settings.php'),
    'rss' => (string) file_get_contents($home.'/www/rutorrent/plugins/rss/action.php'),
    'hddquota' => (string) file_get_contents($home.'/www/rutorrent/plugins/hddquota/action.php'),
    'qbittorrent' => (string) file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf'),
    'tmp_exists' => is_dir($home.'/.tmp'),
    'irssi_exists' => is_dir($home.'/.irssi'),
    'recycle_exists' => is_dir($home.'/www/recycle'),
]
PHP
        );

        $result = $this->pmssRunInlinePhpJson(str_replace('__REPO_ROOT__', var_export($repoRoot, true), $script), ['PMSS_TEST_MODE' => '1']);

        $this->assertStringContainsString('***** Updating user alice', $result['output']);
        $this->assertEquals([], $result['linger']);
        $this->assertTrue($result['tmp_exists']);
        $this->assertTrue($result['irssi_exists']);
        $this->assertTrue($result['recycle_exists']);
        $this->assertStringContainsString('@ob_flush();', $result['filemanager']);
        $this->assertStringContainsString("if (is_readable('/scripts/lib/user/torrentPort.php')) {", $result['deluge']);
        $this->assertStringContainsString('pmssDelugePortEnsureCurrentUser', $result['deluge']);
        $this->assertTrue(strpos($result['deluge'], '.delugePort.py') === false);
        $this->assertStringContainsString("if (is_readable('/scripts/lib/user/torrentPort.php')) {", $result['qbittorrent_frontend']);
        $this->assertStringContainsString('pmssQbittorrentPortEnsureCurrentUser', $result['qbittorrent_frontend']);
        $this->assertTrue(strpos($result['qbittorrent_frontend'], '.qbittorrentPort.py') === false);
        $this->assertStringContainsString('((integer)($tm["minutes"]/((', str_replace('(int)$interval', '((int)$interval)', $result['settings']));
        $this->assertStringContainsString('@ob_flush();', $result['rss']);
        $this->assertStringContainsString('return (int) $field;', $result['hddquota']);
        $this->assertStringContainsString('WebUI\\CSRFProtection=false', $result['qbittorrent']);
        $this->assertStringContainsString('WebUI\\ClickjackingProtection=false', $result['qbittorrent']);
        $this->assertStringContainsString('WebUI\\HostHeaderValidation=false', $result['qbittorrent']);
        $this->assertStringContainsString('Session\\DiskCacheSize=128', $result['qbittorrent']);
        $this->assertStringContainsString('Session\\MaxConnections=300', $result['qbittorrent']);
        $this->assertStringContainsString('Downloads\\DiskWriteCacheSize=128', $result['qbittorrent']);
        $this->assertStringContainsString('Bittorrent\\MaxConnecs=300', $result['qbittorrent']);

        $this->assertPhaseOrder(
            $result['descriptions'],
            [
                'Configuring lighttpd vhost',
                'Installing ruTorrent theme Agent34',
                'Installing unpack plugin',
                'Refreshing user permissions',
            ]
        );
    }

    /**
     * Assert that the listed phase descriptions appear in order.
     *
     * @param array<int, string> $descriptions
     * @param array<int, string> $expected
     */
    private function assertPhaseOrder(array $descriptions, array $expected): void
    {
        $offset = -1;
        foreach ($expected as $needle) {
            $found = false;
            foreach ($descriptions as $index => $description) {
                if ($index <= $offset) {
                    continue;
                }
                if ($description !== $needle) {
                    continue;
                }
                $offset = $index;
                $found = true;
                break;
            }
            $this->assertTrue($found, 'Expected phase in order: '.$needle);
        }
    }

    /**
     * Build the shared subprocess harness for user environment tests.
     */
    private function buildUserEnvironmentScript(string $baseSuffix, string $setup, string $action, string $result): string
    {
        $template = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$base = sys_get_temp_dir().'/pmss-user-env-__BASE_SUFFIX__-'.bin2hex(random_bytes(4));

function pmssUserEnvCleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

__SETUP__
require $repoRoot.'/scripts/lib/update.php';

$GLOBALS['PMSS_USER_STEP_CALLS'] = [];
$GLOBALS['PMSS_LINGER_USERS'] = [];
function runUserStep(string $user, string $description, string $command): int
{
    $GLOBALS['PMSS_USER_STEP_CALLS'][] = [
        'user' => $user,
        'description' => $description,
        'command' => $command,
    ];
    return 0;
}
function pmssEnsureLingerAndDocker(string $user): void
{
    $GLOBALS['PMSS_LINGER_USERS'][] = $user;
}

require $repoRoot.'/scripts/lib/update/users.php';

ob_start();
__ACTION__
$output = ob_get_clean();

echo json_encode(__RESULT__);

pmssUserEnvCleanup($base);
PHP;

        return strtr(
            $template,
            [
                '__BASE_SUFFIX__' => $baseSuffix,
                '__SETUP__' => $setup,
                '__ACTION__' => $action,
                '__RESULT__' => $result,
            ]
        );
    }
}
