<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogErrorPage.php';

class LighttpdWatchdogErrorPageTest extends TestCase
{
    private function watchdogErrorPagePath(string $webRoot, string $username = 'alice'): string
    {
        return $webRoot.'/error-502-'.$username.'.html';
    }

    public function testQuotaOutputShowsExhaustionWhenBlockColumnHasMarker(): void
    {
        $output = "Disk quotas for user demo (uid 1000):\nFilesystem blocks quota limit grace files quota limit grace\n/dev/sda1 10G* 9G 10G 0 0 0\n";
        $this->assertTrue(pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output));
    }

    public function testQuotaOutputShowsExhaustionWhenInodeColumnHasMarker(): void
    {
        $output = "Disk quotas for user demo (uid 1000):\nFilesystem blocks quota limit grace files quota limit grace\n/dev/sda1 10G 12G 12G 5000* 4000 5000\n";
        $this->assertTrue(pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output));
    }

    public function testQuotaOutputIgnoresHealthySnapshot(): void
    {
        $output = "Disk quotas for user demo (uid 1000):\nFilesystem blocks quota limit grace files quota limit grace\n/dev/sda1 10G 12G 12G 200 0 0\n";
        $this->assertFalse(pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output));
    }

    public function testQuotaOutputIgnoresEmptyInput(): void
    {
        $this->assertFalse(pmssLighttpdWatchdogQuotaOutputShowsExhaustion(''));
    }

    public function testQuotaOutputIgnoresUnrelatedLines(): void
    {
        $this->assertFalse(pmssLighttpdWatchdogQuotaOutputShowsExhaustion("quota unavailable\ntry again later\n"));
    }

    public function testParseDfInodeUsePercentReturnsDetectedValue(): void
    {
        $output = "Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/sda1 100 96 4 96% /\n";
        $this->assertSame(96, pmssLighttpdWatchdogParseDfInodeUsePercent($output));
    }

    public function testParseDfInodeUsePercentHandlesFullUsage(): void
    {
        $output = "Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/sda1 100 100 0 100% /\n";
        $this->assertSame(100, pmssLighttpdWatchdogParseDfInodeUsePercent($output));
    }

    public function testParseDfInodeUsePercentReturnsNullForHeaderOnly(): void
    {
        $output = "Filesystem Inodes IUsed IFree IUse% Mounted on\n";
        $this->assertSame(null, pmssLighttpdWatchdogParseDfInodeUsePercent($output));
    }

    public function testParseDfInodeUsePercentReturnsNullForMalformedInput(): void
    {
        $this->assertSame(null, pmssLighttpdWatchdogParseDfInodeUsePercent("/dev/sda1 without percent\n"));
    }

    public function testParseDfInodeUsePercentSkipsHeaderAndReadsLaterLine(): void
    {
        $output = "Filesystem Inodes IUsed IFree IUse% Mounted on\nudev 100 1 99 1% /dev\n/dev/sda1 1000 420 580 42% /\n";
        $this->assertSame(1, pmssLighttpdWatchdogParseDfInodeUsePercent($output));
    }

    public function testDetectReasonChecksHomeInodesBeforeConfigFallback(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-watchdog-bin-');
        $this->pmssWriteExecutableFile($binDir.'/quota', "#!/bin/sh\nexit 1\n");
        $this->pmssWriteExecutableFile($binDir.'/df', <<<'SH'
#!/bin/sh
mount="${2:-/}"
printf '%s\n' 'Filesystem Inodes IUsed IFree IUse% Mounted on'
case "$mount" in
    /home) printf '%s\n' '/dev/home 100 96 4 96% /home' ;;
    /) printf '%s\n' '/dev/root 100 10 90 10% /' ;;
    *) printf '%s\n' "/dev/other 100 10 90 10% ${mount}" ;;
esac
SH
        );
        $homeDir = $this->pmssMakeTempDir('pmss-watchdog-home-');

        $this->pmssWithPathPrefix($binDir, function () use ($homeDir): void {
            $reason = pmssLighttpdWatchdogDetectReason('alice', $homeDir, $homeDir.'/.lighttpd.conf', true);
            $this->assertSame('inode', $reason);
        });
    }

    public function testRenderErrorPageIncludesSpecificStatusAndHomeLink(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('quota');
        $this->assertStringContainsAllStrings(['Your disk quota is full.', 'Connect with SFTP and delete files to free space, then retry.', '<a href="/">Return to the main page.</a>'], $contents);
    }

    public function testRenderErrorPageFallsBackToRestartingMessageForUnknownReason(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('unknown');
        $this->assertStringContainsAllStrings(['Your web service is restarting.', 'This usually settles within 1-2 minutes; please retry shortly.', 'If the issue persists, please open a support ticket.'], $contents);
    }

    public function testRenderErrorPageKeepsTicketGuidanceForQuotaReason(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('quota');
        $this->assertStringContainsString('If the issue persists, please open a support ticket.', $contents);
    }

    public function testRenderErrorPageUsesNoActionGuidanceForPhpReason(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('php');
        $this->assertStringContainsString('This usually resolves within 1-2 minutes. No action needed.', $contents);
        $this->assertStringNotContainsString('If the issue persists, please open a support ticket.', $contents);
    }

    public function testRenderErrorPageUsesNoActionGuidanceForRestartingReason(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('restarting');
        $this->assertStringContainsString('This usually resolves within 1-2 minutes. No action needed.', $contents);
    }

    public function testRenderErrorPageUsesNoActionGuidanceForInodeReason(): void
    {
        $contents = pmssLighttpdWatchdogRenderErrorPage('inode');
        $this->assertStringContainsString('This usually resolves within a few minutes. No action needed.', $contents);
    }

    public function testStaticFallback502PageQualifiesTicketGuidance(): void
    {
        $contents = $this->pmssReadRepoFile('var/www/error-502.html');
        $this->assertStringContainsString('If this continues for more than a few minutes, open a support ticket.', $contents);
    }

    public function testWriteErrorPageCreatesExpectedPerUserPath(): void
    {
        $webRoot = $this->pmssMakeTempDir('pmss-502-root-');
        $this->assertTrue(pmssLighttpdWatchdogWriteErrorPage('alice', 'php', $webRoot));
        $this->assertTrue(file_exists($this->watchdogErrorPagePath($webRoot)));
    }

    public function testDeleteErrorPageRemovesExistingFile(): void
    {
        $webRoot = $this->pmssMakeTempDir('pmss-502-root-');
        $path = $this->watchdogErrorPagePath($webRoot);
        file_put_contents($path, 'demo');
        $this->assertTrue(pmssLighttpdWatchdogDeleteErrorPage('alice', $webRoot));
        $this->assertFalse(file_exists($path));
    }

    public function testDeleteErrorPageSucceedsWhenFileIsAlreadyMissing(): void
    {
        $webRoot = $this->pmssMakeTempDir('pmss-502-root-');
        $this->assertTrue(pmssLighttpdWatchdogDeleteErrorPage('alice', $webRoot));
    }
}
