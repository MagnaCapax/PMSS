<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/appSwitch.php';

final class AppProfilesTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-app-profiles-']; }

    public function testEveryDefinitionUsesKnownAppsAndBooleanSwitches(): void
    {
        $registry = \pmssUserAppSwitchRegistry();
        $profiles = \pmssUserAppProfiles();
        $this->assertSame(['rtorrent' => true, 'pikkumies' => true, 'docker' => true], $profiles['default']);
        foreach ($profiles as $name => $apps) {
            $this->assertSame($name, \pmssUserAppProfileName($name));
            foreach ($apps as $app => $enabled) {
                $this->assertTrue(isset($registry[$app]), $name.' names an unknown app: '.$app);
                $this->assertTrue(is_bool($enabled));
                $this->assertTrue(is_callable($registry[$app]['apply']));
            }
        }
    }

    public function testDefaultStartsRtorrentQueuesPikkumiesAndKeepsDockerOn(): void
    {
        $result = $this->applyProfile('default');
        $this->assertSame('Start rTorrent', $result['starts'][0][0]);
        $this->assertSame(['Queue Pikkumies preinstall'], array_column($result['queues'], 0));
        // Docker on equals the policy default, so nothing is persisted and the store keeps meaning an explicit choice.
        $this->assertFalse(isset($result['config']['dockerEnabled']));
        $this->assertSame([], $result['markers']);
    }

    public function testOpenClawDisablesRtorrentAndQueuesBothPreinstalls(): void
    {
        $result = $this->applyProfile('openclaw');
        $this->assertSame([], $result['starts']);
        $this->assertSame(['Queue Pikkumies preinstall', 'Queue OpenClaw preinstall'], array_column($result['queues'], 0));
        // Docker on equals the policy default, so nothing is persisted and the store keeps meaning an explicit choice.
        $this->assertFalse(isset($result['config']['dockerEnabled']));
        $this->assertSame(['.rtorrentDisable' => "disabled\n"], $result['markers']);
        $this->assertStringContainsAllStrings(['systemd-run', '--slice=user-4321.slice', 'install-openclaw', 'install --yes'], $result['queues'][1][1]);
        // Installs are queued only in the late phase, after quotas and the account environment exist.
        $this->assertSame([], $result['earlyQueues']);
    }

    public function testStorageboxEnablesOnlyStorageMarkersAndQueuesPikkumies(): void
    {
        $result = $this->applyProfile('storagebox');
        $this->assertSame([], $result['starts']);
        $this->assertSame(['Queue Pikkumies preinstall'], array_column($result['queues'], 0));
        // Storage boxes have no use for Docker (#270); the profile is the provisioner's explicit override (#309).
        $this->assertSame('false', $result['config']['dockerEnabled'] ?? null);
        $this->assertSame(['.copypartyEnable' => '', '.rcloneEnable' => '', '.rtorrentDisable' => "disabled\n"], $result['markers']);
    }

    public function testOpenClawSkipReasonsAndDockerRamFloorArePreserved(): void
    {
        foreach ([[2047, true, 'RAM'], [2048, false, 'quota']] as $case) {
            $result = $this->applyProfile('openclaw', $case[0], $case[1]);
            $this->assertSame(['Queue Pikkumies preinstall'], array_column($result['queues'], 0));
            $this->assertStringContainsString($case[2], implode("\n", $result['logs']));
        }
        $result = $this->applyProfile('default', 244);
        $this->assertSame([], $result['queues']);
        $this->assertStringContainsString('RAM below 245 MiB', implode("\n", $result['logs']));
    }

    public function testUnknownRegistryAppIsLoggedAndSkipped(): void
    {
        $result = $this->applyProfile('default', 2048, true, true);
        $this->assertSame([], $result['starts']);
        $this->assertSame(['Queue Pikkumies preinstall'], array_column($result['queues'], 0));
        $this->assertStringContainsString('unknown app; skipped', implode("\n", $result['logs']));
    }

    public function testMarkerSwitchesWriteAsTheAccountAndRefuseSymlinks(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureDir($home);
        $name = posix_geteuid() === 0 ? 'root' : 'alice';
        $user = ['name' => $name];
        $registry = \pmssUserAppSwitchRegistry();
        $markers = ['qbittorrent' => '.qbittorrentEnable', 'deluge' => '.delugeEnable', 'rclone' => '.rcloneEnable'];
        foreach (\pmssToggleAppCatalogRead() as $app => $row) {
            if (isset($row['enable'])) $markers[$app] = $row['enable'];
        }
        $outside = $this->tempDir.'/outside';
        file_put_contents($outside, 'unchanged');
        $this->pmssWithEnv(['PMSS_TEST_MODE' => '1'], function () use ($registry, $markers, $user, $home, $outside): void {
            foreach ($markers as $app => $marker) {
                $switch = $registry[$app]['apply'];
                $this->assertSame('off; no creation marker', $switch(false, $user, $home)['message']);
                $this->assertFalse(file_exists($home.'/'.$marker));
                $this->assertSame('enable marker written', $switch(true, $user, $home)['message']);
                $this->assertSame('', file_get_contents($home.'/'.$marker));
                $this->assertSame(posix_geteuid(), fileowner($home.'/'.$marker));
                $this->assertSame(0600, fileperms($home.'/'.$marker) & 0777);
                unlink($home.'/'.$marker);
                symlink($outside, $home.'/'.$marker);
                $this->assertSame('enable marker failed', $switch(true, $user, $home)['message']);
                $this->assertSame('unchanged', file_get_contents($outside));
            }
            symlink($outside, $home.'/.rtorrentDisable');
            $this->assertTrue($registry['rtorrent']['apply'](false, $user, $home)['fatal']);
            $this->assertSame('unchanged', file_get_contents($outside));
        });
    }

    public function testOrchestrationContainsNoProfileOrAppPolicyLiterals(): void
    {
        foreach (['scripts/addUser.php', 'scripts/lib/user/add/cli.php', 'scripts/lib/user/add/postProvision.php'] as $file) {
            $source = file_get_contents($this->pmssRepoPath($file));
            foreach (array_unique(array_merge(array_keys(\pmssUserAppProfiles()), array_keys(\pmssUserAppSwitchRegistry()))) as $name) {
                $this->assertFalse(strpos($source, "'".$name."'") !== false, $file.' hardcodes '.$name);
                $this->assertFalse(strpos($source, '"'.$name.'"') !== false, $file.' hardcodes '.$name);
            }
        }
        $this->assertFalse(file_exists($this->pmssRepoPath('scripts/lib/user/add/appProfile.php')));
    }

    /** Capture the real registry's decisions; only queued commands are recorded rather than executed. */
    private function applyProfile(string $profile, int $memory = 2048, bool $quota = true, bool $missingApp = false): array
    {
        $home = $this->pmssMakeTempDir('pmss-app-home-');
        $this->pmssEnsureDir($home.'/bin');
        file_put_contents($home.'/bin/install-pikkumies.sh', "#!/bin/sh\n");
        file_put_contents($home.'/bin/install-openclaw', '<?php');
        $this->pmssWriteExecutableFile($home.'/stubs/id', "#!/bin/sh\necho 4321\n", 0755, 0700);
        $this->pmssWriteExecutableFile($home.'/stubs/quota', $quota ? "#!/bin/sh\necho '/dev/md4 123 1000 1250 100 60100 75125'\n" : "#!/bin/sh\nexit 1\n");
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/user/appSwitch.php'), true).';'
            .'$home = '.var_export($home, true).'; $missingApp = '.var_export($missingApp, true).';'
            .'$user = '.var_export(['name' => posix_geteuid() === 0 ? 'root' : 'alice', 'memory' => $memory, 'appProfile' => $profile], true).';';
        $script .= <<<'PHP'
function logProvisionMessage(string $message): void { $GLOBALS['logs'][] = $message; }
function runProvisionStep(string $label, string $command): void { $GLOBALS['queues'][] = [$label, $command]; }
function pmssAddUserFatalExit($status, $message, $code): void { throw new RuntimeException($message); }
$GLOBALS['logs'] = $GLOBALS['queues'] = [];
$registry = pmssUserAppSwitchRegistry();
$registry['pikkumies']['apply'] = static function ($enabled, $user, $home) {
    return pmssAddUserPikkumiesSwitch($enabled, $user, $home, $home.'/state');
};
if ($missingApp) unset($registry['rtorrent']);
$starts = pmssUserAppProfileApply($user, $home, $registry);
$GLOBALS['earlyQueues'] = $GLOBALS['queues'];
pmssUserAppProfileApply($user, $home, $registry, 'late');
$markers = [];
foreach (glob($home.'/.*') as $path) if (is_file($path)) $markers[basename($path)] = file_get_contents($path);
ksort($markers);
echo json_encode(['starts' => $starts, 'markers' => $markers, 'config' => pmssUserAppProfileConfig($user),
    'logs' => $GLOBALS['logs'], 'queues' => $GLOBALS['queues'], 'earlyQueues' => $GLOBALS['earlyQueues']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PATH' => $home.'/stubs:'.getenv('PATH'), 'PMSS_TEST_MODE' => '1', 'PMSS_DRY_RUN' => '0']);
    }
}
