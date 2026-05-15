<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

class PmssStatsContextSafetyTest extends TestCase
{
    public function testResolveContextNormalizesValidUsernameOverrides(): void
    {
        $context = \pmssStatsResolveContext(['user' => ' Alice ']);

        $this->assertSame('alice', $context['user']);
        $this->assertSame('/home/alice', $context['home']);
        $this->assertSame('/home/alice/.rtorrent.socket', $context['socket_path']);
    }

    public function testResolveContextFallsBackFromRelativeAndMalformedPathOverrides(): void
    {
        $context = \pmssStatsResolveContext([
            'user' => 'alice',
            'home' => "../unsafe\n",
            'config_dir' => 'relative/config',
            'socket_path' => "socket\0path",
            'version_file' => '../version',
            'cgroup_dir' => 'relative/cgroup',
        ]);

        $this->assertSame('/home/alice', $context['home']);
        $this->assertSame('/etc/seedbox/config', $context['config_dir']);
        $this->assertSame('/home/alice/.rtorrent.socket', $context['socket_path']);
        $this->assertSame('/etc/seedbox/config/version', $context['version_file']);
        $this->assertTrue($context['cgroup_dir'] === '' || $context['cgroup_dir'][0] === '/');
    }

    public function testResolveContextPreservesExplicitAbsoluteOverrides(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-stats-context-');
        $context = \pmssStatsResolveContext([
            'user' => 'alice',
            'home' => $baseDir.'/home/alice/',
            'config_dir' => $baseDir.'/config/',
            'socket_path' => $baseDir.'/home/alice/.rtorrent.socket',
            'version_file' => $baseDir.'/config/version',
            'cgroup_dir' => $baseDir.'/cgroup/',
        ]);

        $this->assertSame($baseDir.'/home/alice', $context['home']);
        $this->assertSame($baseDir.'/config', $context['config_dir']);
        $this->assertSame($baseDir.'/home/alice/.rtorrent.socket', $context['socket_path']);
        $this->assertSame($baseDir.'/config/version', $context['version_file']);
        $this->assertSame($baseDir.'/cgroup', $context['cgroup_dir']);
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
            'PMSS_STATS_USER' => 'alice',
        ], function (): void {
            $context = \pmssStatsResolveContext();

            $this->assertSame('/home/alice', $context['home']);
        });
    }

    public function testResolveContextUsesSafeHomeFallbackForTraversalLikeUsers(): void
    {
        $context = \pmssStatsResolveContext(['user' => '../../escape']);

        $this->assertSame('../../escape', $context['user']);
        $this->assertSame('/home', $context['home']);
        $this->assertSame('/home/.rtorrent.socket', $context['socket_path']);
    }

    public function testResolveContextFallsBackWhenOverridesAreBlank(): void
    {
        $context = \pmssStatsResolveContext([
            'user' => 'alice',
            'home' => '   ',
            'config_dir' => ' ',
            'socket_path' => '',
            'version_file' => "\t",
        ]);

        $this->assertSame('/home/alice', $context['home']);
        $this->assertSame('/etc/seedbox/config', $context['config_dir']);
        $this->assertSame('/home/alice/.rtorrent.socket', $context['socket_path']);
        $this->assertSame('/etc/seedbox/config/version', $context['version_file']);
    }
}
