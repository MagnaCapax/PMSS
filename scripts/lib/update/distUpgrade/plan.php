<?php
/**
 * Dist-upgrade target and single-step plan helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** @return array{action:string, from:?string, to:?string, message:string} */
function pmssDistUpgradePlan(string $action, ?string $from, ?string $to, string $message = ''): array
{
    return ['action' => $action, 'from' => $from, 'to' => $to, 'message' => $message];
}

/** @return array{action:string, from:?string, to:?string, message:string} */
function pmssResolveDistUpgradeStep(string $currentMajor, string $maxMajor): array
{
    $currentMajorInt = (int) $currentMajor;
    $maxMajorInt = (int) $maxMajor;

    if ($currentMajorInt > $maxMajorInt) {
        return pmssDistUpgradePlan('error', $currentMajor, null, sprintf('Safety halt: Current version is %s but the requested maximum is %s.', $currentMajor, $maxMajor));
    }
    if ($currentMajorInt === $maxMajorInt) {
        return pmssDistUpgradePlan('noop', $currentMajor, null, sprintf('No dist-upgrade required: current version is %s and requested maximum is %s.', $currentMajor, $maxMajor));
    }
    if (!pmssDistUpgradeIsAllowedMajor($currentMajor) || $currentMajorInt >= 13) {
        return pmssDistUpgradePlan('noop', null, null, 'No upgrade recipe for Debian '.$currentMajor);
    }

    $from = (string) $currentMajorInt;
    $next = (string) ($currentMajorInt + 1);
    if ((int) $next > $maxMajorInt) {
        return pmssDistUpgradePlan('error', $from, null, sprintf('Safety halt: Current version is %s. The next logical upgrade is to %s, but your maximum is %s.', $currentMajor, $next, $maxMajor));
    }

    return pmssDistUpgradePlan(
        'upgrade',
        $from,
        $next,
        $maxMajor !== $next ? sprintf('Requested maximum is %s; performing safe incremental upgrade to %s.', $maxMajor, $next) : ''
    );
}

function pmssResolveTargetVersion(string $input): string
{
    $key = strtolower($input);
    if ($key === '') {
        return '';
    }

    $target = pmssDistUpgradeIsAllowedMajor($key) ? $key : (string) pmssVersionFromCodename($key);
    return pmssDistUpgradeIsAllowedMajor($target) ? $target : '';
}

function pmssDistUpgradeIsAllowedMajor(string $major): bool
{
    static $allowed = ['10' => true, '11' => true, '12' => true, '13' => true];
    return isset($allowed[$major]);
}
