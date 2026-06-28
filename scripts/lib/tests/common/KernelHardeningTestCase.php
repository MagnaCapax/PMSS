<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

abstract class KernelHardeningTestCase extends TestCase
{
    /** Run a registry hardening helper while recording the commands it would execute. */
    protected function pmssRunKernelHardeningWithCalls(callable $operation, array $env, array &$logs, array &$calls): void
    {
        $runner = $this->pmssKernelHardeningCaptureCalls($calls);
        $this->pmssWithEnv($env, function () use ($operation, &$logs, $runner): void {
            call_user_func($operation, $this->pmssMakeArrayLogger($logs), $runner);
        });
    }

    /** Run a registry hardening helper with a no-op command runner. */
    protected function pmssRunKernelHardeningNoop(callable $operation, array $env, array &$logs): void
    {
        $runner = $this->pmssKernelHardeningNoopRunner();
        $this->pmssWithEnv($env, function () use ($operation, &$logs, $runner): void {
            call_user_func($operation, $this->pmssMakeArrayLogger($logs), $runner);
        });
    }

    /** Run the ssh-keysign hardening helper, which has no command runner argument. */
    protected function pmssRunKernelHardeningSshKeysign(array $env, array &$logs): void
    {
        $this->pmssWithEnv($env, function () use (&$logs): void {
            \pmssEnsureSshKeysignSuidStrip($this->pmssMakeArrayLogger($logs));
        });
    }

    /** Return a runStep-compatible fixture that records command descriptions. */
    protected function pmssKernelHardeningCaptureCalls(array &$calls): callable
    {
        return function (string $description, string $command) use (&$calls): int {
            $calls[] = [$description, $command];
            return 0;
        };
    }

    /** Return a runStep-compatible fixture for tests that only need side effects. */
    protected function pmssKernelHardeningNoopRunner(): callable
    {
        return function (): int {
            return 0;
        };
    }
}
