<?php
namespace PMSS\Tests;

// Match the on-disk path and class name for the filesystem helper; both
// file and class are named `userFilesystem` on case-sensitive systems.
require_once dirname(__DIR__, 2).'/user/userFilesystem.php';

class UserRepositoryListTest extends TestCase
{
    public function testListHomeUsers(): void
    {
        $users = \userFilesystem::listHomeUsers();
        $this->assertTrue(is_array($users));
    }

    /** GH#990: discovery uses provisioning evidence, never a healthy-config requirement. */
    public function testProvisionedDiscoveryRetainsDamagedAndLegacyAccounts(): void
    {
        $root = $this->pmssMakeTempDir('pmss-provisioned-discovery-');
        $this->pmssWriteFiles($root, [
            'config/users/fixtur02.json' => '{}',
            'config/users/fixtur03.json' => 'broken json',
            'config/users/fixtur05.json' => '{}', // Missing home still needs maintenance.
            'config/users/fixtur09.json' => '{}', // Missing system account stays excluded.
            'home/fixtur04/.rtorrent.rc' => '',
            'home/fixtur07/.config/keep' => '',
            'home/fixtur11/www/index.html' => '', // Generic home content is not evidence.
        ]);
        $this->assertTrue(symlink($root.'/missing', $root.'/config/users/fixtur06.json'));
        $this->assertTrue(symlink($root.'/missing', $root.'/home/fixtur07/.rtorrent.rc'));
        $script = 'namespace ProvisionedDiscoveryFixture; require_once '
            .var_export($this->pmssRepoPath('scripts/lib/user/userRepository.php'), true).';';
        $script .= <<<'PHP'
class UserConfigStore extends \UserConfigStore {}
class UserRepository extends \UserRepository {}
// Only discovery inputs are synthetic; config/marker observations use real temp files.
function opendir($path) { return false; }
function file($path, $flags) {
    $rows = [];
    for ($index = 1; $index <= 11; ++$index) {
        $user = sprintf('fixtur%02d', $index);
        $rows[] = $user.':x:1000:1000::/home/'.$user.':/bin/bash';
    }
    return $rows;
}
function pmssUserAccountLookup($name) { return $name === 'fixtur09' ? null : ['name' => $name]; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/userFilesystem.php', 'ProvisionedDiscoveryFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/users.php', 'ProvisionedDiscoveryFixture');
        $script .= <<<'PHP'
$store = new UserConfigStore();
echo json_encode(['all' => users::listHomeUsers(), 'provisioned' => users::listHomeUsers(true),
    'invalidRecord' => $store->hasRecord('../fixtur02')]);
PHP;
        $legacy = ['fixtur08' => [], 'fixtur10' => null];
        foreach ([$legacy, ['users' => $legacy]] as $aggregate) {
            $this->pmssWriteFile($root.'/runtime/users.json', json_encode($aggregate));
            $result = $this->pmssRunInlinePhpJson($script, [
                'PMSS_CONFIG_DIR' => $root.'/config', 'PMSS_HOME_DIR' => $root.'/home', 'PMSS_TEST_MODE' => '1',
            ]);
            $this->assertSame(['fixtur01', 'fixtur02', 'fixtur03', 'fixtur04', 'fixtur05',
                'fixtur06', 'fixtur07', 'fixtur08', 'fixtur10', 'fixtur11'], $result['all']);
            $this->assertSame(['fixtur02', 'fixtur03', 'fixtur04', 'fixtur05',
                'fixtur06', 'fixtur07', 'fixtur08', 'fixtur10'], $result['provisioned']);
            $this->assertFalse($result['invalidRecord']);
        }
    }

    public function testUpdaterRequestsProvisionedAccountsBeforeSummaryCounting(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/userMaintenance.php', [
            '$users = users::listHomeUsers(true);',
            "\$summary = ['total' => count(\$users)",
        ]);
    }
}
