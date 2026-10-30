<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

class PmssStatsContextSafetyTest extends TestCase
{
    public function testPathResolveRejectsControlBytesBeforeNormalization(): void
    {
        foreach (["\0", "\r", "\n"] as $byte) {
            foreach ([$byte.'/candidate/', '/candidate/'.$byte, '/can'.$byte.'didate/'] as $path) {
                $this->assertSame('/fallback/', \pmssStatsContextPathResolve($path, '/fallback/'));
                $this->assertSame('/fallback', \pmssStatsContextPathResolve($path, '/fallback/', true));
                $this->assertSame('', \pmssStatsContextPathResolve($path, '', true));
            }
        }
    }

    public function testPathResolvePreservesValidAndMissingPathNormalization(): void
    {
        foreach ([
            ['/candidate', '/candidate', '/candidate'],
            [' /candidate/ ', '/candidate/', '/candidate'],
            ["\t/candidate/\t", '/candidate/', '/candidate'],
            ['/candidate with spaces/0', '/candidate with spaces/0', '/candidate with spaces/0'],
            ['', '/fallback/', '/fallback'],
            ['   ', '/fallback/', '/fallback'],
            ['relative/path', '/fallback/', '/fallback'],
        ] as [$path, $file, $directory]) {
            $this->assertSame($file, \pmssStatsContextPathResolve($path, '/fallback/'));
            $this->assertSame($directory, \pmssStatsContextPathResolve($path, '/fallback/', true));
        }
    }

    public function testResolveContextRejectsControlBytesInAbsoluteOverridePaths(): void
    {
        foreach (["\0", "\r", "\n"] as $byte) {
            foreach ([$byte.'/candidate', '/candidate'.$byte] as $path) {
                $context = \pmssStatsResolveContext([
                    'user' => 'fixtur01', 'home' => $path, 'config_dir' => $path,
                    'socket_path' => $path, 'version_file' => $path, 'cgroup_dir' => '/fixture/cgroup',
                ]);

                $this->pmssAssertArraySubsetSame([
                    'home' => '/home/fixtur01', 'config_dir' => '/etc/seedbox/config',
                    'socket_path' => '/home/fixtur01/.rtorrent.socket',
                    'version_file' => '/etc/seedbox/config/version', 'cgroup_dir' => '/fixture/cgroup',
                ], $context);
            }
        }
    }

    public function testResolveContextRejectsControlBytesInEnvironmentPaths(): void
    {
        // Environment variables cannot carry NUL; exercise those bytes through overrides above.
        foreach (["\r", "\n"] as $byte) {
            foreach ([$byte.'/candidate', '/candidate'.$byte, '/can'.$byte.'didate'] as $path) {
                $this->pmssWithEnv([
                    'PMSS_STATS_HOME' => $path, 'PMSS_STATS_CONFIG_DIR' => $path,
                    'PMSS_STATS_SOCKET_PATH' => $path, 'PMSS_STATS_VERSION_FILE' => $path,
                    'PMSS_STATS_CGROUP_DIR' => '/fixture/cgroup',
                ], function (): void {
                    $context = \pmssStatsResolveContext(['user' => 'fixtur01']);

                    $this->pmssAssertArraySubsetSame([
                        'home' => '/home/fixtur01', 'config_dir' => '/etc/seedbox/config',
                        'socket_path' => '/home/fixtur01/.rtorrent.socket',
                        'version_file' => '/etc/seedbox/config/version', 'cgroup_dir' => '/fixture/cgroup',
                    ], $context);
                });
            }
        }
    }

    public function testResolveContextNormalizesValidUsernameOverrides(): void
    {
        $context = \pmssStatsResolveContext(['user' => ' Fixtur02 ']);

        $this->pmssAssertArraySubsetSame([
            'user' => 'fixtur02', 'home' => '/home/fixtur02', 'socket_path' => '/home/fixtur02/.rtorrent.socket',
        ], $context);
    }

    public function testResolveContextFallsBackFromRelativeAndMalformedPathOverrides(): void
    {
        $context = \pmssStatsResolveContext([
            'user' => 'fixtur02',
            'home' => "../unsafe\n",
            'config_dir' => 'relative/config',
            'socket_path' => "socket\0path",
            'version_file' => '../version',
            'cgroup_dir' => 'relative/cgroup',
        ]);

        $this->pmssAssertArraySubsetSame([
            'home' => '/home/fixtur02', 'config_dir' => '/etc/seedbox/config',
            'socket_path' => '/home/fixtur02/.rtorrent.socket', 'version_file' => '/etc/seedbox/config/version',
        ], $context);
        $this->assertTrue($context['cgroup_dir'] === '' || $context['cgroup_dir'][0] === '/');
    }

    public function testResolveContextPreservesExplicitAbsoluteOverrides(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-stats-context-');
        $context = \pmssStatsResolveContext([
            'user' => 'fixtur02',
            'home' => $baseDir.'/home/fixtur02/',
            'config_dir' => $baseDir.'/config/',
            'socket_path' => $baseDir.'/home/fixtur02/.rtorrent.socket',
            'version_file' => $baseDir.'/config/version',
            'cgroup_dir' => $baseDir.'/cgroup/',
        ]);

        $this->pmssAssertArraySubsetSame([
            'home' => $baseDir.'/home/fixtur02', 'config_dir' => $baseDir.'/config',
            'socket_path' => $baseDir.'/home/fixtur02/.rtorrent.socket', 'version_file' => $baseDir.'/config/version',
            'cgroup_dir' => $baseDir.'/cgroup',
        ], $context);
    }

    public function testResolveContextUsesShellHomeOnlyWithoutStatsUser(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-stats-context-home-');
        $this->pmssWithEnv([
            'HOME' => $baseDir.'/shell-home',
            'PMSS_STATS_HOME' => null,
            'PMSS_STATS_USER' => null,
        ], function () use ($baseDir): void {
            $context = \pmssStatsResolveContext(['user' => '']);

            $this->assertSame($baseDir.'/shell-home', $context['home']);
        });

        $this->pmssWithEnv([
            'HOME' => $baseDir.'/shell-home',
            'PMSS_STATS_HOME' => null,
            'PMSS_STATS_USER' => 'fixtur02',
        ], function (): void {
            $context = \pmssStatsResolveContext();

            $this->assertSame('/home/fixtur02', $context['home']);
        });
    }

    public function testResolveContextUsesSafeHomeFallbackForTraversalLikeUsers(): void
    {
        $context = \pmssStatsResolveContext(['user' => '../../escape']);

        $this->pmssAssertArraySubsetSame([
            'user' => '../../escape', 'home' => '/home', 'socket_path' => '/home/.rtorrent.socket',
        ], $context);
    }

    public function testResolveContextFallsBackWhenOverridesAreBlank(): void
    {
        $context = \pmssStatsResolveContext([
            'user' => 'fixtur02',
            'home' => '   ',
            'config_dir' => ' ',
            'socket_path' => '',
            'version_file' => "\t",
        ]);

        $this->pmssAssertArraySubsetSame([
            'home' => '/home/fixtur02', 'config_dir' => '/etc/seedbox/config',
            'socket_path' => '/home/fixtur02/.rtorrent.socket', 'version_file' => '/etc/seedbox/config/version',
        ], $context);
    }
}
