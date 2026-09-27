<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WatchdogInstallerSafetyTest extends TestCase
{
    public function testRequiredSetupStepsFailClosedBeforeServiceActivation(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/apps/watchdog.php', [
            'function pmssWatchdogRunRequiredStep(string $description, string $command): bool',
            'runStep($description, $command) === 0',
            "logMessage('[WARN] '.\$description.' failed; leaving watchdog service disabled.');",
            "['Ensuring watchdog script directory exists'",
            "['Installing watchdog configuration'",
            "['Installing watchdog network check'",
            'if (!pmssWatchdogRunRequiredStep($description, $command)) return;',
        ]);
    }

    public function testWatchdogDeviceRewriteFailureStopsBeforeEnable(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/watchdog.php');
        $this->assertStringContainsString(
            "logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');\n            return;\n",
            $source
        );
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/watchdog.php', [
            "@file_put_contents('/etc/watchdog.conf', \$updated) === false",
            "logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');",
            "runStep('Unmasking watchdog service'",
            "runStep('Enabling watchdog service'",
        ]);
    }
}
