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
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/watchdog.php', [
            "logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');\n            return;\n",
        ]);
        $this->assertOrderedStrings([
            "!pmssFileWriteComplete('/etc/watchdog.conf', \$updated)",
            "logMessage('[WARN] Unable to update watchdog device path; leaving service disabled.');",
            "runStep('Enabling watchdog service'",
        ], $source);
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
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/watchdog.php', [
            'function pmssWatchdogDevice(array $candidates',
            'file_exists($candidate)',
            "filetype(\$candidate) === 'char'",
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'masked'",
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'enabled'",
            'systemctl disable --now watchdog.service',
        ], ['is_file(\'/dev/watchdog', 'systemctl unmask watchdog']);
        $this->assertOrderedStrings([
            "pmssSystemdUnitState('is-enabled', 'watchdog.service') === 'masked'",
            "\$device = pmssWatchdogDevice();",
            "if (\$device === '') {",
            'systemctl disable --now watchdog.service',
            "['Installing watchdog configuration'",
            "runStep('Enabling watchdog service'",
        ], $source);
    }
}
