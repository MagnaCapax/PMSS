<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 4).'/etc/skel/www/webDockerInactiveNote.php';
require_once __DIR__.'/../common/TestCase.php';

class webDockerInactiveNoteTest extends TestCase
{
    public function testReturnsEmptyNoteForNonInactiveStatus(): void
    {
        $this->assertSame('', \pmssWebDockerInactiveNote('active', true));
    }

    public function testShowsDebianVersionSpecificGrubGuidanceFromOsRelease(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-web-docker-note-os-');
        file_put_contents($fixture.'/os-release', "ID=debian\nVERSION_ID=12\n");
        file_put_contents($fixture.'/debian_version', "12.13\n");
        file_put_contents($fixture.'/cmdline', "quiet splash\n");

        $note = \pmssWebDockerInactiveNote('inactive', true, $fixture.'/os-release', $fixture.'/debian_version', $fixture.'/cmdline');

        $this->assertStringContainsString('Debian 12: User bus restricted.', $note);
        $this->assertStringContainsString('systemd.unified_cgroup_hierarchy=0', $note);
    }

    public function testFallsBackToDebianVersionFileWhenOsReleaseIsMissing(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-web-docker-note-fallback-');
        file_put_contents($fixture.'/debian_version', "11.11\n");
        file_put_contents($fixture.'/cmdline', "panic=1\n");

        $note = \pmssWebDockerInactiveNote('inactive', null, $fixture.'/missing-os-release', $fixture.'/debian_version', $fixture.'/cmdline');

        $this->assertStringContainsString('Debian 11', $note);
        $this->assertStringContainsString('User bus restricted.', $note);
    }

    public function testShowsDisabledPolicyGuidanceWhenBootSettingIsPresent(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-web-docker-note-disabled-');
        file_put_contents($fixture.'/cmdline', "quiet systemd.unified_cgroup_hierarchy=0\n");

        $note = \pmssWebDockerInactiveNote('inactive', false, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('currently disabled', $note);
        $this->assertStringContainsString('controls below to enable it', $note);
    }

    public function testShowsRuntimeGuidanceWhenPolicyIsEnabled(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-web-docker-note-enabled-');
        file_put_contents($fixture.'/cmdline', "quiet systemd.unified_cgroup_hierarchy=0\n");

        $note = \pmssWebDockerInactiveNote('inactive', true, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('not currently running', $note);
        $this->assertStringContainsString('start it again', $note);
    }

    public function testShowsGenericControlGuidanceWhenPolicyIsUnknown(): void
    {
        $fixture = $this->pmssMakeTempDir('pmss-web-docker-note-unknown-');
        file_put_contents($fixture.'/cmdline', "quiet unified_cgroup_hierarchy=0\n");

        $note = \pmssWebDockerInactiveNote('inactive', null, $fixture.'/missing-os-release', $fixture.'/missing-debian-version', $fixture.'/cmdline');

        $this->assertStringContainsString('Docker controls below to enable it', $note);
    }
}
