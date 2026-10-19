<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/chromium.php';

/** Chromium browser runtime app step (ADR 0123): distro gate, free-space floor, idempotence, no setuid sandbox. */
final class ChromiumRuntimeInstallTest extends TestCase
{
    private const GIB = 1073741824;

    /** Run the step with recording fakes; $installed lists packages dpkg reports as installed. */
    private function runStep(int $major, array $installed = [], array $freeGiB = [], array $extra = []): array
    {
        $calls = ['status' => [], 'free' => [], 'run' => [], 'log' => []];
        $ops = $extra + [
            'major' => $major,
            'packagesReady' => true,
            'status' => static function (string $package) use (&$calls, $installed): string {
                $calls['status'][] = $package;
                return $installed[$package] ?? (in_array($package, $installed, true) ? 'install ok installed' : 'unknown ok not-installed');
            },
            'freeBytes' => static function (string $path) use (&$calls, $freeGiB): ?float {
                $calls['free'][] = $path;
                return array_key_exists($path, $freeGiB) ? ($freeGiB[$path] === null ? null : $freeGiB[$path] * self::GIB) : 50.0 * self::GIB;
            },
            'run' => static function (string $description, string $command) use (&$calls, $extra): int {
                $calls['run'][] = $command;
                return $extra['rc'] ?? 0;
            },
            'log' => static function (string $line) use (&$calls): void { $calls['log'][] = $line; },
        ];
        \pmssChromiumRuntimeEnsure($ops);
        return $calls;
    }

    public function testOnlyDebian12And13AreServed(): void
    {
        foreach ([0, 10, 11, 14] as $major) {
            $calls = $this->runStep($major);
            $this->assertSame([[], [], [], []], array_values($calls), "Debian {$major} must be a silent no-op");
        }
        $this->assertSame(['chromium'], $this->runStep(12)['status']);
        $this->assertSame(['chromium', 'slirp4netns'], $this->runStep(13)['status']);
    }

    public function testBelowTheFloorOnAnyCheckedFilesystemLogsOneSkipAndInstallsNothing(): void
    {
        foreach (['/' => 2.99, '/usr' => 1.0, '/var' => 0.5] as $path => $gib) {
            $calls = $this->runStep(12, [], [$path => $gib]);
            $this->assertSame([], $calls['run'], "{$path} below the floor must not install");
            $this->assertSame(1, count($calls['log']), 'exactly one skip line: '.implode(' | ', $calls['log']));
            $this->assertStringContainsString('[SKIP] chromium runtime: '.$path.' free space', $calls['log'][0]);
        }
        $unknown = $this->runStep(13, [], ['/var' => null]);
        $this->assertSame([], $unknown['run'], 'unmeasurable free space fails closed');
        $this->assertStringContainsString('free space unknown', $unknown['log'][0]);

        $atFloor = $this->runStep(12, [], ['/' => 3.0, '/usr' => 3.0, '/var' => 3.0]);
        $this->assertSame(1, count($atFloor['run']), 'exactly at the floor installs');
        $this->assertSame(['/', '/usr', '/var'], $atFloor['free']);
    }

    public function testAlreadyInstalledMakesNoAptCallAndMeasuresNothing(): void
    {
        foreach ([12 => ['chromium'], 13 => ['chromium', 'slirp4netns']] as $major => $installed) {
            $calls = $this->runStep($major, $installed, ['/' => 0.1]);
            $this->assertSame([[], [], []], [$calls['free'], $calls['run'], $calls['log']], "Debian {$major}: converged host stays quiet");
        }
        $partial = $this->runStep(13, ['chromium']);
        $this->assertSame(1, count($partial['run']));
        $this->assertStringContainsString("--no-install-recommends 'slirp4netns'", $partial['run'][0]);
        $this->assertStringNotContainsString("'chromium'", $partial['run'][0], 'only the missing part is installed');
    }

    public function testNoCommandEverNamesTheSetuidSandboxOrOverridesHolds(): void
    {
        $commands = array_merge($this->runStep(12)['run'], $this->runStep(13)['run'], $this->runStep(13, ['slirp4netns'])['run']);
        $this->assertSame(3, count($commands));
        foreach ($commands as $command) {
            $this->assertStringContainsString('apt-get ', $command);
            $this->assertStringContainsString('install -y --no-install-recommends ', $command, 'recommends would pull chromium-sandbox on older Debian builds');
            $this->assertStringNotContainsString('chromium-sandbox', $command);
            $this->assertStringNotContainsString('allow-change-held-packages', $command);
        }
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/update/apps/chromium.php');
        $this->assertStringNotContainsString('allow-change-held-packages', $source);
    }

    public function testAdministratorOptOutAndUnreadyPackagePhaseAreRespected(): void
    {
        foreach (['deinstall ok config-files', 'purge ok not-installed', 'hold ok not-installed'] as $state) {
            $calls = $this->runStep(12, ['chromium' => $state]);
            $this->assertSame([[], []], [$calls['free'], $calls['run']], "'{$state}' is an opt-out");
            $this->assertStringContainsString('[SKIP] chromium runtime: chromium is selected', $calls['log'][0]);
        }
        $halfDone = $this->runStep(12, ['chromium' => 'install ok half-configured']);
        $this->assertSame(1, count($halfDone['run']), 'an interrupted install is finished, not skipped');

        $unready = $this->runStep(13, [], [], ['packagesReady' => false]);
        $this->assertSame([[], []], [$unready['status'], $unready['run']]);
        $this->assertSame(['[SKIP] chromium runtime: package phase not complete'], $unready['log']);
    }

    public function testFailuresStaySoft(): void
    {
        $failed = $this->runStep(12, [], [], ['rc' => 100]);
        $this->assertSame(1, count($failed['run']));
        $this->assertStringContainsString('[WARN] chromium runtime install failed (rc=100)', $failed['log'][0]);

        $thrown = $this->runStep(12, [], [], ['status' => static function (): string { throw new \RuntimeException('dpkg unavailable'); }]);
        $this->assertSame([], $thrown['run']);
        $this->assertSame(['[WARN] chromium runtime skipped: dpkg unavailable'], $thrown['log']);
    }

    public function testSelectionBaselinesDoNotCarryChromium(): void
    {
        // The baselines are applied additively on every host regardless of free space (ADR 0123).
        foreach (['12', '13'] as $major) {
            $baseline = (string) file_get_contents(dirname(__DIR__, 2).'/update/dpkg/selections-debian'.$major.'.txt');
            $this->assertSame(0, preg_match('/^chromium/m', $baseline), "selections-debian{$major}.txt must not list chromium packages");
        }
    }
}
