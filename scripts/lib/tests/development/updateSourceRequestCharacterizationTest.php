<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';

/** Lock the standalone updater's source grammar, including its historical edge cases. */
class UpdateSourceRequestCharacterizationTest extends TestCase
{
    public function testOptionFormsAndPrecedence(): void
    {
        $defaults = [
            'dry_run' => false, 'dist_upgrade' => false, 'skip_self_update' => false,
            'scripts_only' => false, 'spec' => 'git/main', 'repo' => null, 'branch' => null,
        ];
        foreach ([
            [['git/main', '--dry-run', '--scripts-only', '--skip-self-update'], ['dry_run' => true, 'scripts_only' => true, 'skip_self_update' => true]],
            [['git/main', '--scriptonly'], ['scripts_only' => true]],
            [['git/main', '--dist-upgrade= 11 '], ['dist_upgrade' => ' 11 ']],
            [['git/main', '--dist-upgrade='], ['dist_upgrade' => '']],
            [['git/main', '--dist-upgrade=0'], ['dist_upgrade' => '0']],
            [['git/main', '--dist-upgrade=11=12'], ['dist_upgrade' => '11=12']],
            [['git/main', '--dist-upgrade=11', '--dist-upgrade'], ['dist_upgrade' => true]],
            [['git/main', '--dist-upgrade', '--dist-upgrade=12'], ['dist_upgrade' => '12']],
            [['git/main', 'release', '--unknown'], []],
            [['--dry-run=yes', 'git/main'], ['spec' => '--dry-run=yes']],
            [['--repo', 'git/main'], ['spec' => '--repo']],
            [['', 'release', 'git/main'], ['spec' => 'release']],
            [['release', '--repo=  ', '--branch= '], ['spec' => 'release', 'repo' => '', 'branch' => '']],
            [['release', '--branch= beta '], ['spec' => 'git/'.\DEFAULT_REPO.':beta', 'branch' => 'beta']],
            [['release', '--repo= https://example.test/project.git '], ['spec' => 'git/https://example.test/project.git:main', 'repo' => 'https://example.test/project.git']],
            [['release', '--repo=first', '--repo=second', '--branch=one=two'], ['spec' => 'git/second:one=two', 'repo' => 'second', 'branch' => 'one=two']],
            [['release', '--repo=0', '--branch=0'], ['spec' => 'git/0:0', 'repo' => '0', 'branch' => '0']],
        ] as [$arguments, $changes]) {
            $this->assertSame(array_replace($defaults, $changes), \parseArguments(array_merge(['update.php'], $arguments)), json_encode($arguments));
        }
    }

    public function testNormalizationKeepsLegacySpellingsAndInvalidResults(): void
    {
        foreach ([
            [' Git/Dev ', 'Git/Dev'], ['git:', ''], ['git/', ''],
            ["git\tdev name", 'git/devname'], ["git dev\tname", "git/dev\tname"],
            ['release_', 'release:_'], ['RELEASE /', 'release'],
            ['RELEASE:tag', 'RELEASE:tag'], ['release::tag', 'release::tag'],
            ['https://example.test/project.git', 'git/https://example.test/project.git'],
            ['ssh://example.test/project.git', 'git/ssh://example.test/project.git'],
            ['HTTPS://example.test/project.git', ''], ['feature/topic', ''],
            ['0', 'git/0'], ['', ''], ['   ', ''], ['--unknown', 'git/--unknown'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, \normaliseSpec($input), $input);
        }
    }

    public function testDecodedSourceAndSerializedVersion(): void
    {
        foreach ([
            ['release', 'release', \DEFAULT_REPO, '', '', 'release'],
            [' RELEASE/ ', 'release', \DEFAULT_REPO, '', '', 'release'],
            ['release:::tag', 'release', \DEFAULT_REPO, '', 'tag', 'release:tag'],
            ['release/tag:part', 'release', \DEFAULT_REPO, '', 'tag:part', 'release:tag:part'],
            ['git/', 'git', \DEFAULT_REPO, 'main', '', 'git/main'],
            ['git:', 'git', \DEFAULT_REPO, 'main', '', 'git/main'],
            ['GIT/Dev', 'git', \DEFAULT_REPO, 'Dev', '', 'git/Dev'],
            ['git/0', 'git', \DEFAULT_REPO, '0', '', 'git/0'],
            ['git/name:beta', 'git', \DEFAULT_REPO, 'beta', '', 'git/beta'],
            ['git/name:0', 'git', \DEFAULT_REPO, '0', '', 'git/0'],
            ['git/owner/project:beta', 'git', 'owner/project', 'beta', '', 'git/owner/project:beta'],
            ['git/owner/project:', 'git', 'owner/project', 'main', '', 'git/owner/project:main'],
            ['git/dev:2025-02-03 12:34', 'git', \DEFAULT_REPO, 'dev', '2025-02-03 12:34', 'git/dev:2025-02-03 12:34'],
            ['git/:2025-02-03', 'git', \DEFAULT_REPO, 'main', '2025-02-03', 'git/main:2025-02-03'],
            ['git/dev:2025-02-31', 'git', \DEFAULT_REPO, 'dev', '2025-02-31', 'git/dev:2025-02-31'],
            ['git/dev:bad-date', 'git', \DEFAULT_REPO, 'bad-date', '', 'git/bad-date'],
            ['git/https://example.test/project.git/', 'git', 'https://example.test/project.git', 'main', '', 'git/https://example.test/project.git:main'],
            ['git/https://example.test/project.git:0', 'git', 'https://example.test/project.git', 'main', '', 'git/https://example.test/project.git:main'],
            ['git/https://example.test:8443/project.git', 'git', 'https://example.test', '8443/project.git', '', 'git/https://example.test:8443/project.git'],
            ['git/ssh://example.test/project.git:beta:2025-02-03', 'git', 'ssh://example.test/project.git', 'beta', '2025-02-03', 'git/ssh://example.test/project.git:beta:2025-02-03'],
        ] as [$input, $type, $repo, $branch, $pin, $version]) {
            $decoded = \parseSpec($input);
            $this->assertSame(['type' => $type, 'repo' => $repo, 'branch' => $branch, 'pin' => $pin], $decoded, $input);
            $this->assertSame($version, \pmssBuildVersionSpec($decoded), $input);
        }
    }

    public function testVersionSerializationDefaultsAndLiteralZero(): void
    {
        foreach ([
            [[], 'git/main'], [['type' => 'release', 'pin' => '0'], 'release:0'],
            [['type' => 'git', 'branch' => '0', 'pin' => '0'], 'git/0:0'],
            [['repo' => '', 'branch' => ''], 'git/:'],
            [['repo' => null, 'branch' => null, 'pin' => null], 'git/main'],
        ] as [$spec, $expected]) {
            $this->assertSame($expected, \pmssBuildVersionSpec($spec));
        }
    }
}
