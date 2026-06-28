<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

class NormaliseSpecTest extends TestCase
{
    public function testNormalisesSpecs(): void
    {
        foreach ([
            'git main' => 'git/main',
            'main' => 'git/main',
            'release 2025-07-12' => 'release:2025-07-12',
            'release:' => 'release',
            'release/' => 'release',
            'git/dev:2024-07-01' => 'git/dev:2024-07-01',
            'git/https://example.com/repo.git:beta' => 'git/https://example.com/repo.git:beta',
            '  git    dev  ' => 'git/dev',
            'https://example.com/repo.git' => 'git/https://example.com/repo.git',
            'release_1' => 'release:_1',
            "git\nmain" => 'git/main',
            'release' => 'release',
            'git/dev:2025-02-03 12:34' => 'git/dev:2025-02-03 12:34',
            '@@bad??!!' => '',
        ] as $input => $expected) {
            $this->assertEquals($expected, normaliseSpec($input), 'Spec: '.$input);
        }
    }

    public function testDefaultSpecFormat(): void
    {
        if (is_file(\VERSION_FILE)) {
            throw new SkipTest('VERSION_FILE exists on this host; default spec depends on stored spec');
        }

        $parsed = \parseArguments(['update.php']);
        $this->assertEquals('git/main', $parsed['spec'] ?? '');
    }

    public function testParseArgumentsCharacterizationCases(): void
    {
        foreach ([
            'mode flags and legacy scripts-only alias' => [['update.php', 'release', '--dry-run', '--scriptonly', \SELF_UPDATE_SKIP_FLAG], ['dry_run' => true, 'dist_upgrade' => false, 'skip_self_update' => true, 'scripts_only' => true, 'spec' => 'release', 'repo' => null, 'branch' => null]],
            'dist-upgrade value' => [['update.php', 'git/dev', '--dist-upgrade=11'], ['dry_run' => false, 'dist_upgrade' => '11', 'skip_self_update' => false, 'scripts_only' => false, 'spec' => 'git/dev', 'repo' => null, 'branch' => null]],
            'dist-upgrade requires explicit cap later' => [['update.php', 'git/dev', '--dist-upgrade'], ['dry_run' => false, 'dist_upgrade' => true, 'skip_self_update' => false, 'scripts_only' => false, 'spec' => 'git/dev', 'repo' => null, 'branch' => null]],
            'repo branch override replaces positional spec' => [['update.php', 'release', '--repo= https://example.test/repo.git ', '--branch= beta '], ['dry_run' => false, 'dist_upgrade' => false, 'skip_self_update' => false, 'scripts_only' => false, 'spec' => 'git/https://example.test/repo.git:beta', 'repo' => 'https://example.test/repo.git', 'branch' => 'beta']],
        ] as $label => $case) {
            [$argv, $expected] = $case;
            $this->assertEquals($expected, \parseArguments($argv), $label);
        }
    }
}
