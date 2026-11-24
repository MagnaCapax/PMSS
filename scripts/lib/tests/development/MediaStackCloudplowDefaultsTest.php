<?php
/** Verify account-local, inactive Cloudplow bootstrap defaults without running upstream code. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackCloudplowDefaultsTest extends TestCase
{
    public function testFreshDefaultsHaveAccountPathsAndNoTransferJobs(): void
    {
        list($home, $output) = $this->bootstrap($this->upstreamConfig());
        $config = json_decode(file_get_contents($home.'/.config/cloudplow/config.json'));
        $this->assertStringContainsString('rc:0', $output);
        $this->assertStringContainsString('Cloudplow is idle', $output);
        $this->assertSame($home.'/.config/rclone/rclone.conf', $config->core->rclone_config_path);
        $this->assertSame('rclone', $config->core->rclone_binary_path);
        $this->assertSame($home.'/data', $config->remotes->google->upload_folder);
        foreach (['upload_remote', 'hidden_remote', 'sync_remote'] as $key) {
            $this->assertSame('', $config->remotes->google->$key);
        }
        foreach (['uploader', 'hidden', 'syncer', 'notifications'] as $key) {
            $this->assertTrue(is_object($config->$key));
            $this->assertSame([], (array) $config->$key);
        }
        $this->assertSame('move', $config->remotes->google->rclone_command);
        $this->assertSame(0600, fileperms($home.'/.config/cloudplow/config.json') & 0777);
        $this->assertSame([], glob($home.'/.config/cloudplow/.config.*'));
    }

    public function testRerunPreservesCustomerConfigWithoutBootstrapping(): void
    {
        $existing = "{\n  \"customer_config\": true\n}\n";
        list($home, $output) = $this->bootstrap('', 'fail', $existing);
        $this->assertStringContainsString('rc:0', $output);
        $this->assertSame($existing, file_get_contents($home.'/.config/cloudplow/config.json'));
        $this->assertFalse(file_exists($home.'/generator-ran'));
    }

    public function testMalformedDefaultsNeverBecomeTheLiveConfig(): void
    {
        foreach (['', '{broken', 'null', '[]', '{}', '{"core":true,"remotes":{"google":{}}}',
            '{"core":{},"remotes":{"google":false}}'] as $json) {
            list($home, $output) = $this->bootstrap($json);
            $this->assertStringContainsString('rc:1', $output);
            $this->assertFalse(file_exists($home.'/.config/cloudplow/config.json'));
            $this->assertSame([], glob($home.'/.config/cloudplow/.config.*'));
        }
    }

    public function testFailedGeneratorNeverPublishesAConfig(): void
    {
        list($home, $output) = $this->bootstrap($this->upstreamConfig(), 'fail');
        $this->assertStringContainsString('rc:1', $output);
        $this->assertFalse(file_exists($home.'/.config/cloudplow/config.json'));
        $this->assertSame([], glob($home.'/.config/cloudplow/.config.*'));
    }

    public function testConcurrentCustomerConfigIsNotOverwritten(): void
    {
        list($home, $output) = $this->bootstrap($this->upstreamConfig(), 'race');
        $this->assertStringContainsString('rc:1', $output);
        $this->assertSame('customer-created', file_get_contents($home.'/.config/cloudplow/config.json'));
        $this->assertSame([], glob($home.'/.config/cloudplow/.config.*'));
    }

    public function testDirectoryAtConfigPathRejectsPublication(): void
    {
        list($home, $output) = $this->bootstrap($this->upstreamConfig(), 'directory');
        $this->assertStringContainsString('rc:1', $output);
        $this->assertTrue(is_dir($home.'/.config/cloudplow/config.json'));
        $this->assertFalse(file_exists($home.'/.config/cloudplow/config.json/pmss.json'));
        $this->assertSame([], glob($home.'/.config/cloudplow/.config.*'));
    }

    private function upstreamConfig(): string
    {
        // Schema and sample paths from the installer's pinned utils/config.py.
        return json_encode([
            'core' => ['dry_run' => false, 'rclone_binary_path' => '/usr/bin/rclone',
                'rclone_config_path' => '/home/fixtur01/.config/rclone/rclone.conf'],
            'remotes' => ['google' => ['upload_folder' => '/mnt/local/Media',
                'upload_remote' => 'google:/Media', 'hidden_remote' => 'google:',
                'sync_remote' => 'google:/Media', 'rclone_command' => 'move']],
            'uploader' => ['google' => ['check_interval' => 30, 'max_size_gb' => 200]],
            'hidden' => ['/mnt/local/.unionfs-fuse' => ['hidden_remotes' => ['google']]],
            'syncer' => (object) [],
            'notifications' => (object) [],
        ]);
    }

    private function bootstrap(string $json, string $mode = '', ?string $existing = null): array
    {
        // Include shell metacharacters in HOME to check that paths remain data.
        $home = $this->pmssMakeTempDir('pmss-cloudplow-defaults-')."/fixture space ' \$";
        $this->pmssWriteRelativeFile($home, '.bin/cloudplow/cloudplow/cloudplow.py', 'fixture');
        $this->pmssWriteRelativeFile($home, 'upstream.json', $json);
        if ($existing !== null) {
            $this->pmssWriteRelativeFile($home, '.config/cloudplow/config.json', $existing);
        }
        $this->pmssWriteExecutableFile($home.'/.bin/cloudplow/bin/python3', <<<'BASH'
#!/usr/bin/env bash
printf invoked > "$HOME/generator-ran"
[[ "$CLOUDPLOW_FIXTURE_MODE" != fail ]] || exit 2
for arg in "$@"; do
    case "$arg" in --config=*) cp "$HOME/upstream.json" "${arg#*=}" ;; esac
done
if [[ "$CLOUDPLOW_FIXTURE_MODE" == race ]]; then
    printf customer-created > "$HOME/.config/cloudplow/config.json"
elif [[ "$CLOUDPLOW_FIXTURE_MODE" == directory ]]; then
    mkdir "$HOME/.config/cloudplow/config.json"
fi
BASH
        );
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\n"
            .'export HOME='.escapeshellarg($home).' CLOUDPLOW_FIXTURE_MODE='.escapeshellarg($mode)."\n"
            .'log_warn() { printf "%s\\n" "$*"; }'."\n"
            .$this->pmssExtractShellFunction($source, 'media_stack_cloudplow_config_ensure')."\n"
            .'if media_stack_cloudplow_config_ensure; then echo rc:0; else echo rc:1; fi'."\n");
        return [$home, $output];
    }
}
