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
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$base = sys_get_temp_dir().'/pmss-user-env-invalid-'.bin2hex(random_bytes(4));

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

@mkdir($base, 0755, true);
putenv('PMSS_HOME_DIR='.$base.'/home');
putenv('PMSS_SKEL_DIR='.$base.'/skel');
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
pmssUpdateUserEnvironment('missing-user', 'sha123');
$output = ob_get_clean();

echo json_encode([
    'steps' => $GLOBALS['PMSS_USER_STEP_CALLS'],
    'linger' => $GLOBALS['PMSS_LINGER_USERS'],
    'output' => $output,
]);

pmssUserEnvCleanup($base);
PHP;

        $result = $this->runPhpJson(str_replace('__REPO_ROOT__', var_export($repoRoot, true), $script));

        $this->assertEquals([], $result['steps']);
        $this->assertEquals([], $result['linger']);
        $this->assertEquals('', $result['output']);
    }

    public function testUpdateUserEnvironmentRunsStablePhasesAndLingerHook(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$base = sys_get_temp_dir().'/pmss-user-env-valid-'.bin2hex(random_bytes(4));
$homeRoot = $base.'/home';
$skelRoot = $base.'/skel';
$user = 'alice';
$home = $homeRoot.'/'.$user;

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

@mkdir($home.'/data', 0755, true);
@mkdir($home.'/.lighttpd', 0755, true);
@mkdir($home.'/.config/qBittorrent', 0755, true);
@mkdir($home.'/www/rutorrent/php', 0755, true);
@mkdir($home.'/www/rutorrent/plugins/rss', 0755, true);
@mkdir($home.'/www/rutorrent/plugins/theme/themes', 0755, true);
@mkdir($home.'/www/rutorrent/share/users/'.$user.'/settings', 0755, true);
@mkdir($home.'/www/rutorrent/share/settings', 0755, true);
@mkdir($skelRoot.'/.irssi', 0755, true);
@mkdir($skelRoot.'/www/rutorrent/plugins/hddquota', 0755, true);

file_put_contents($home.'/.rtorrent.rc', "dummy\n");
file_put_contents($home.'/.lighttpd/php.ini', "display_errors = On\n");
file_put_contents($home.'/.config/qBittorrent/qBittorrent.conf', "[Preferences]\nWebUI\\CSRFProtection=true\nWebUI\\ClickjackingProtection=true\nWebUI\\HostHeaderValidation=true\n");
file_put_contents($home.'/www/filemanager.php', "before\n        ob_flush();\nafter\n");
file_put_contents($home.'/www/rutorrent/php/settings.php', '((integer)($tm["minutes"]/$interval))*$interval+$interval,');
file_put_contents($home.'/www/rutorrent/plugins/rss/action.php', "before\nob_flush();\nafter\n");
file_put_contents($skelRoot.'/.irssi/config', "test\n");
file_put_contents($skelRoot.'/www/rutorrent/plugins/hddquota/sample.txt', "quota\n");

putenv('PMSS_HOME_DIR='.$homeRoot);
putenv('PMSS_SKEL_DIR='.$skelRoot);
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
pmssUpdateUserEnvironment($user, '');
$output = ob_get_clean();

echo json_encode([
    'descriptions' => array_values(array_map(static function (array $entry): string {
        return $entry['description'];
    }, $GLOBALS['PMSS_USER_STEP_CALLS'])),
    'linger' => $GLOBALS['PMSS_LINGER_USERS'],
    'output' => $output,
    'filemanager' => (string) file_get_contents($home.'/www/filemanager.php'),
    'settings' => (string) file_get_contents($home.'/www/rutorrent/php/settings.php'),
    'rss' => (string) file_get_contents($home.'/www/rutorrent/plugins/rss/action.php'),
    'qbittorrent' => (string) file_get_contents($home.'/.config/qBittorrent/qBittorrent.conf'),
    'tmp_exists' => is_dir($home.'/.tmp'),
    'irssi_exists' => is_dir($home.'/.irssi'),
    'recycle_exists' => is_dir($home.'/www/recycle'),
]);

pmssUserEnvCleanup($base);
PHP;

        $result = $this->runPhpJson(str_replace('__REPO_ROOT__', var_export($repoRoot, true), $script));

        $this->assertStringContainsString('***** Updating user alice', $result['output']);
        $this->assertEquals(['alice'], $result['linger']);
        $this->assertTrue($result['tmp_exists']);
        $this->assertTrue($result['irssi_exists']);
        $this->assertTrue($result['recycle_exists']);
        $this->assertStringContainsString('@ob_flush();', $result['filemanager']);
        $this->assertStringContainsString('((integer)($tm["minutes"]/((', str_replace('(int)$interval', '((int)$interval)', $result['settings']));
        $this->assertStringContainsString('@ob_flush();', $result['rss']);
        $this->assertStringContainsString('WebUI\\CSRFProtection=false', $result['qbittorrent']);
        $this->assertStringContainsString('WebUI\\ClickjackingProtection=false', $result['qbittorrent']);
        $this->assertStringContainsString('WebUI\\HostHeaderValidation=false', $result['qbittorrent']);

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
     * Execute a short PHP snippet and decode the emitted JSON payload.
     */
    private function runPhpJson(string $script): array
    {
        $output = (string) @shell_exec(
            'PMSS_TEST_MODE=1 '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>/dev/null'
        );
        $decoded = json_decode($output, true);
        $this->assertTrue(is_array($decoded), 'Expected JSON output, got: '.trim($output));
        return $decoded;
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
}
