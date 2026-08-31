<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RtorrentThrottleInitTest extends TestCase
{
    public function testStartupHelperRestoresThrottleForCurrentUser(): void
    {
        $fixture = $this->startupFixture('return true;');
        $result = $this->runStartupHelper($fixture['script']);

        $this->assertSame(0, $result['rc'], $result['output']);
        $observed = json_decode((string) file_get_contents($fixture['observed']), true);
        $this->assertTrue(is_array($observed), 'Expected throttle helper observation');
        $this->assertMatches('/^[a-z_][a-z0-9_-]{0,31}$/D', (string) ($observed['user'] ?? ''));
        $this->assertSame($fixture['php_root'], (string) ($observed['cwd'] ?? ''));
    }

    public function testStartupHelperReturnsFailureWhenRestoreFails(): void
    {
        $fixture = $this->startupFixture('return false;');
        $result = $this->runStartupHelper($fixture['script']);

        $this->assertSame(1, $result['rc'], 'Failed throttle restore should return non-zero');
    }

    public function testStartupHelperFailsSoftWhenPluginIsMissing(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rtorrent-throttle-missing-');
        $script = $home.'/.rtorrentThrottleInit.php';
        $this->pmssEnsureDir($home.'/www/rutorrent/php');
        copy($this->pmssRepoPath('etc/skel/.rtorrentThrottleInit.php'), $script);

        $result = $this->runStartupHelper($script);

        $this->assertSame(1, $result['rc']);
        $this->assertSame('', $result['output']);
    }

    public function testStartupHelperPreservesCustomNamedThrottleDefinitions(): void
    {
        foreach ([
            "throttle.up = thr_0, 64\n",
            "throttle_down = thr_1, 128\n",
        ] as $customConfig) {
            $fixture = $this->startupFixture('return true;');
            file_put_contents(dirname($fixture['script']).'/.rtorrent.rc.custom', $customConfig);

            $result = $this->runStartupHelper($fixture['script']);

            $this->assertSame(0, $result['rc'], $result['output']);
            $this->assertFalse(is_file($fixture['observed']), 'Custom named throttle should skip plugin restore');
        }
    }

    public function testStartupHelperIgnoresCommentedCustomThrottleExample(): void
    {
        $fixture = $this->startupFixture('return true;');
        file_put_contents(dirname($fixture['script']).'/.rtorrent.rc.custom', "# throttle.up = thr_0, 64\n");

        $result = $this->runStartupHelper($fixture['script']);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertTrue(is_file($fixture['observed']), 'Commented example should not suppress plugin restore');
    }

    public function testSkeletonSchedulesAndDistributesStartupHelper(): void
    {
        $hook = 'schedule2 = throttle_init,5,0,"execute.nothrow=sh,-c,php ~/.rtorrentThrottleInit.php& exit 0"';
        $customConfig = $this->pmssReadRepoFile('etc/skel/.rtorrent.rc.custom');
        $filesystemSource = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');

        $this->assertSame(1, substr_count($customConfig, $hook));
        $this->assertStringNotContainsString('initplugins.php', $customConfig);
        $this->assertStringContainsString("'.rtorrentThrottleInit.php',", $filesystemSource);
    }

    /** Build a customer-home fixture with a narrow throttle plugin stub. */
    private function startupFixture(string $obtainResult): array
    {
        $home = $this->pmssMakeTempDir('pmss-rtorrent-throttle-');
        $phpRoot = $this->pmssEnsureDir($home.'/www/rutorrent/php');
        $pluginRoot = $this->pmssEnsureDir($home.'/www/rutorrent/plugins/throttle');
        $script = $home.'/.rtorrentThrottleInit.php';
        $observed = $home.'/throttle-observed.json';
        copy($this->pmssRepoPath('etc/skel/.rtorrentThrottleInit.php'), $script);
        file_put_contents($pluginRoot.'/throttle.php', '<?php
class rThrottle
{
    public static function load()
    {
        return new self();
    }
    public function obtain()
    {
        file_put_contents('.var_export($observed, true).', json_encode([
            "user" => $_SERVER["REMOTE_USER"] ?? "",
            "cwd" => getcwd(),
        ]));
        '.$obtainResult.'
    }
}
');

        return ['script' => $script, 'observed' => $observed, 'php_root' => $phpRoot];
    }

    /** Execute the copied customer helper without touching a real account. */
    private function runStartupHelper(string $script): array
    {
        return $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
    }
}
