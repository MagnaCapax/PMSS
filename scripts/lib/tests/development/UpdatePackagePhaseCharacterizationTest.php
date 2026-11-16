<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock update-step2.php's package phase without executing package or service commands. */
class UpdatePackagePhaseCharacterizationTest extends TestCase
{
    public function testHealthyPhaseTraceAndCompletionState(): void
    {
        $result = $this->phaseRun([]);
        $this->assertSame(['trace' => $this->healthyTrace(), 'ready' => true, 'sameFailure' => false], $result);
    }

    public function testBaselineRecoveryRetainsRetryGatesWarningsAndLaterSteps(): void
    {
        foreach ([[true, true], [true, false], [false, false]] as [$recover, $retry]) {
            $expected = $this->healthyTrace();
            $insert = [
                ['log', 'deferred repository diagnostic'],
                ['log', '[WARN] Dpkg baseline application reported issues; attempting recovery'],
                ['recover', 'Attempting apt fix-broken install (dpkg baseline recovery)'],
            ];
            if ($recover) $insert[] = ['call', 'Reapplying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [12, true]];
            if (!$recover || !$retry) {
                $insert[] = ['log', '[ERROR] Dpkg baseline still failing after recovery attempt; continuing with caution'];
                $insert[] = ['json', ['event' => 'package_phase', 'status' => 'warn', 'reason' => 'dpkg_baseline']];
            }
            array_splice($expected, 8, 0, $insert);
            $result = $this->phaseRun(['baseline' => [false, $retry], 'recover' => [$recover, false], 'message' => 'deferred repository diagnostic']);
            $this->assertSame(['trace' => $expected, 'ready' => true, 'sameFailure' => false], $result);
        }
    }

    public function testDockerSignalKeepsJsonFieldOrderAndChangeDiagnostic(): void
    {
        $expected = $this->healthyTrace();
        $expected[15] = ['changed', ['docker-ce' => '1'], ['docker-ce' => '2']];
        $expected[16] = ['env', 'PMSS_DOCKER_PACKAGES_CHANGED=1'];
        $expected[17] = ['json', ['event' => 'docker_package_versions', 'changed' => true,
            'before' => ['docker-ce' => '1'], 'after' => ['docker-ce' => '2']]];
        array_splice($expected, 18, 0, [['log', '[INFO] Docker runtime package versions changed; rootless daemons will restart during user maintenance']]);
        $this->assertSame(['trace' => $expected, 'ready' => true, 'sameFailure' => false], $this->phaseRun(['after' => ['docker-ce' => '2']]));
    }

    public function testUnknownVersionIsPassedToRepositoryButNotToBaselineOrCompat(): void
    {
        $expected = $this->healthyTrace();
        $expected[1][3] = ['debian', 0, 'logmsg'];
        $expected[2][3] = [null, true];
        foreach ([8, 9, 10] as $index) $expected[$index][3] = [null];
        $this->assertSame(['trace' => $expected, 'ready' => true, 'sameFailure' => false], $this->phaseRun(['version' => 0]));
    }

    public function testThrownOperationsStopAtOriginalBoundaryWithoutMarkingComplete(): void
    {
        foreach (['RuntimeException', 'Error'] as $class) {
            foreach ([1, 2, 7, 8, 12, 14, 17] as $index) {
                $expected = array_slice($this->healthyTrace(), 0, $index + 1);
                $this->assertSame(['trace' => $expected, 'ready' => false, 'sameFailure' => true],
                    $this->phaseRun(['throwAt' => $index, 'throwClass' => $class]));
            }
        }
    }

    /** Expected command and log bytes from the original inline package phase. */
    private function healthyTrace(): array
    {
        return [
            ['run', 'Attempting apt fix-broken install (pre-package phase)', 'APT --fix-broken install -y'],
            ['call', 'Refreshing package repositories', 'pmssRefreshRepositories', ['debian', 12, 'logmsg']],
            ['call', 'Applying distro dpkg baseline selections', 'pmssApplyDpkgSelections', [12, true]],
            ['call', 'Applying system service disable/mask policy (pre-app)', 'pmssStopDisableMaskSeedboxSystemServices', []],
            ['call', 'Purging failed unbound daemon if present', 'pmssPurgeFailedUnbound', []],
            ['call', 'Ensuring systemd service guard boot unit', 'pmssEnsureSystemdServicesGuardBootUnit', []],
            ['run', 'Removing residual Apache packages', 'APT purge -y apache2 apache2-bin apache2-data libapache2-mod-php7.4 || true'],
            ['call', 'Hardening legacy Apache systemd unit (post-purge)', 'pmssStopDisableMaskSystemdUnit', ['apache2', 'Apache httpd (legacy)', true]],
            ['call', 'Holding libssl3/openssl for PECL ssh2 compat (dpkg-direct guard)', 'pmssHoldLibssl3ForPeclSsh2Compat', [12]],
            ['call', 'Healing openssh-server post-cascade if missing/deleted', 'pmssHealOpensshServerIfMissing', [12]],
            ['call', 'Ensuring openssh-server is libssl3-3.0.17-compatible (canonical baseline downgrade)', 'pmssEnsureOpensshCompatibleWithHeldLibssl3', [12]],
            ['log', '[OK] Package phase relies on dpkg baseline selections only'],
            ['recover', 'Attempting apt fix-broken install (post-package phase)'],
            ['run', 'Removing packages no longer required', 'APT autoremove -y'],
            ['versions'],
            ['changed', ['docker-ce' => '1'], ['docker-ce' => '1']],
            ['env', 'PMSS_DOCKER_PACKAGES_CHANGED=0'],
            ['json', ['event' => 'docker_package_versions', 'changed' => false, 'before' => ['docker-ce' => '1'], 'after' => ['docker-ce' => '1']]],
            ['env', 'PMSS_PACKAGE_PHASE=complete'],
            ['json', ['event' => 'package_phase', 'status' => 'ok']],
        ];
    }

    /** Namespace shims record effects; the real module supplies all phase ordering and policy. */
    private function phaseRun(array $options): array
    {
        $script = <<<'PHP'
namespace PackagePhaseFixture;
function record(array $row) {
    $GLOBALS['trace'][] = $row;
    if (count($GLOBALS['trace']) - 1 === ($GLOBALS['options']['throwAt'] ?? -1)) throw $GLOBALS['failure'];
}
function aptCmd($args) { return 'APT '.$args; }
function runStep($label, $command) { record(['run', $label, $command]); return 9; }
function pmssRunProfiledCallable($label, $callback, $args = []) {
    record(['call', $label, $callback, $args]);
    return $callback === 'pmssApplyDpkgSelections' ? array_shift($GLOBALS['options']['baseline']) : null;
}
function pmssRunProfiledCallableBatch($steps) {
    foreach ($steps as $step) pmssRunProfiledCallable(...$step);
}
function pmssRecoverDpkgBaseline($label) { record(['recover', $label]); return array_shift($GLOBALS['options']['recover']); }
function pmssDockerPackageVersions() { record(['versions']); return $GLOBALS['options']['after']; }
function pmssDockerPackageVersionsChanged($before, $after) { record(['changed', $before, $after]); return $before !== $after; }
function logmsg($message) { record(['log', $message]); }
function pmssLogJson($payload) { record(['json', $payload]); }
function putenv($assignment) { record(['env', $assignment]); return true; }
PHP;
        $options += ['baseline' => [true], 'recover' => [true], 'after' => ['docker-ce' => '1'], 'version' => 12, 'message' => ''];
        $script .= '$GLOBALS["options"] = '.var_export($options, true).'; $GLOBALS["trace"] = []; $GLOBALS["PMSS_PACKAGES_READY"] = false;';
        $script .= '$failureClass = $GLOBALS["options"]["throwClass"] ?? "RuntimeException"; $GLOBALS["failure"] = new $failureClass("phase fault");';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/packagePhase.php', 'PackagePhaseFixture');
        $script .= '$same = false; try { pmssUpdateStep2PackagePhase(["name" => "debian", "repoVersion" => $GLOBALS["options"]["version"],'
            .'"baselineVersion" => $GLOBALS["options"]["version"] > 0 ? $GLOBALS["options"]["version"] : null, "repoMessage" => $GLOBALS["options"]["message"]], ["docker-ce" => "1"]);'
            .'} catch (\\Throwable $caught) { if ($caught !== $GLOBALS["failure"]) throw $caught; $same = true; }'
            .'echo json_encode(["trace" => $GLOBALS["trace"], "ready" => $GLOBALS["PMSS_PACKAGES_READY"], "sameFailure" => $same]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
