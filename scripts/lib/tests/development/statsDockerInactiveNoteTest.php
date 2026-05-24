<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class statsDockerInactiveNoteTest extends TestCase
{
    public function testReturnsEmptyNoteForNonInactiveStatus(): void
    {
        $this->assertSame('', $this->renderNote('active', true, '', '', 'quiet'));
    }

    public function testShowsDebianVersionSpecificGrubGuidanceFromOsRelease(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-stats-docker-note-os-');
        file_put_contents($fixture.'/os-release', "ID=debian\nVERSION_ID=12\n");
        file_put_contents($fixture.'/debian_version', "12.13\n");
        file_put_contents($fixture.'/cmdline', "quiet splash\n");

        $note = $this->renderNote('inactive', true, $fixture.'/os-release', $fixture.'/debian_version', $fixture.'/cmdline');

        $this->assertStringContainsString('Debian 12: User bus restricted.', $note);
        $this->assertStringContainsString('systemd.unified_cgroup_hierarchy=0', $note);
    }

    public function testFallsBackToDebianVersionFileWhenOsReleaseIsMissing(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-stats-docker-note-fallback-');
        file_put_contents($fixture.'/debian_version', "11.11\n");
        file_put_contents($fixture.'/cmdline', "panic=1\n");

        $note = $this->renderNote('inactive', null, $fixture.'/missing-os-release', $fixture.'/debian_version', $fixture.'/cmdline');

        $this->assertStringContainsString('Debian 11', $note);
        $this->assertStringContainsString('User bus restricted.', $note);
    }

    public function testShowsDisabledPolicyGuidanceWhenBootSettingIsPresent(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-stats-docker-note-disabled-');
        file_put_contents($fixture.'/cmdline', "quiet systemd.unified_cgroup_hierarchy=0\n");

        $note = $this->renderNote('inactive', false, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('currently disabled by policy', $note);
        $this->assertStringContainsString('Contact support if it should be enabled', $note);
    }

    public function testShowsRuntimeGuidanceWhenPolicyIsEnabled(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-stats-docker-note-enabled-');
        file_put_contents($fixture.'/cmdline', "quiet systemd.unified_cgroup_hierarchy=0\n");

        $note = $this->renderNote('inactive', true, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('not currently running', $note);
        $this->assertStringContainsString('Contact support if it should be restarted', $note);
    }

    public function testShowsGenericControlGuidanceWhenPolicyIsUnknown(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-stats-docker-note-unknown-');
        file_put_contents($fixture.'/cmdline', "quiet unified_cgroup_hierarchy=0\n");

        $note = $this->renderNote('inactive', null, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('Contact support if it should be enabled for this account', $note);
    }

    private function renderNote(string $status, ?bool $policy, string $osRelease, string $debianVersion, string $cmdline): string
    {
        $script = 'define("PMSS_STATS_HELPERS_ONLY", true);'
            .'require '.var_export($this->pmssRepoPath('etc/skel/www/stats.php'), true).';'
            .'echo json_encode(pmssStatsDockerInactiveNote('
            .var_export($status, true).','
            .var_export($policy, true).','
            .var_export($osRelease, true).','
            .var_export($debianVersion, true).','
            .var_export($cmdline, true)
            .'), JSON_UNESCAPED_SLASHES);';

        $decoded = json_decode($this->pmssRunInlinePhp($script), true);
        return is_string($decoded) ? $decoded : '';
    }
}
