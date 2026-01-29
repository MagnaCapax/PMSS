<?php
/**
 * Library helper: System Interface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

/**
 * Interface for system-level interactions required by Cgroup logic.
 * Allows mocking for hermetic testing.
 */
interface SystemInterface
{
    public function getCgroupMode(): string;
    public function getUid(string $user): int;
    public function execute(string $command): ?string;
    public function readFile(string $path): ?string;
    public function getTotalMemoryMiB(): int;
    public function resolveDevice(string $device): string;
    public function requireRoot(): void;
}
