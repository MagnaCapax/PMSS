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
            "!pmssFileWriteComplete('/etc/watchdog.conf', \$updated)",
            "logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');",
            "runStep('Enabling watchdog service'",
        ]);
    }

    public function testAlternateDeviceRequiresReadableAndRewritableConfiguration(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/watchdog.php', [
            "if (\$device !== '/dev/watchdog') {",
            "\$config = @file_get_contents('/etc/watchdog.conf');",
            'if (!is_string($config)) {',
            'Unable to read watchdog device configuration; leaving service disabled.',
            'if ($updated === null) {',
            'Unable to prepare watchdog device configuration; leaving service disabled.',
            "runStep('Enabling watchdog service'",
        ]);
    }

    public function testMaskedAndDevicelessUnitsAreHandledBeforeInstallation(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/watchdog.php');
        $this->assertStringContainsAllStrings([
            'function pmssWatchdogDevice(array $candidates',
            'file_exists($candidate)',
            "filetype(\$candidate) === 'char'",
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'masked'",
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'enabled'",
            'systemctl disable --now watchdog.service',
        ], $source);
        $this->assertStringNotContainsString('is_file(\'/dev/watchdog', $source);
        $this->assertStringNotContainsString('systemctl unmask watchdog', $source);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/update/apps/watchdog.php', [
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'masked'",
            "\$device = pmssWatchdogDevice();",
            "if (\$device === '') {",
            'systemctl disable --now watchdog.service',
            "['Installing watchdog configuration'",
            "runStep('Enabling watchdog service'",
        ]);
    }
}
