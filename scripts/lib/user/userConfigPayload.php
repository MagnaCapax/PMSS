<?php
/** Pure durable user-config normalization; loaded by userConfigStore.php.
 * @license GPL-3.0-only
 */

/** Retain legacy fields, policy defaults and sorted payload keys without filesystem access. */
function pmssUserConfigPayloadNormalise(array $payload): array
{
    // Additive back-compat: map legacy rtorrentRam -> ramMiB but keep rtorrentRam if present.
    if (!isset($payload['ramMiB']) && is_numeric($payload['rtorrentRam'] ?? null)) {
        $payload['ramMiB'] = (int)$payload['rtorrentRam'];
    }

    if ((!isset($payload['billingServiceId']) || !is_numeric($payload['billingServiceId']) || (int) $payload['billingServiceId'] <= 0)
        && isset($payload['billingId'])
        && is_numeric($payload['billingId'])
    ) {
        $payload['billingServiceId'] = (int) $payload['billingId'];
    }
    unset($payload['billingId']);

    foreach (['ramMiB', 'rtorrentPort', 'quota', 'quotaBurst', 'billingServiceId', 'billingClientId', 'trafficLimit',
        'trafficCapMbit', 'bonusPercent', 'bonusTrafficGiB', 'uploadThrottleKib',
        'CPUWeight', 'IOWeight', 'IOReadIOPS', 'IOWriteIOPS', 'ioLatencyMs'] as $key) {
        if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '' && is_numeric($payload[$key])) {
            $payload[$key] = (int)$payload[$key];
        }
    }

    foreach (['billingServiceId', 'billingClientId'] as $key) {
        if (!isset($payload[$key]) || !is_numeric($payload[$key])) $payload[$key] = 0;
    }

    $payload['suspended'] = array_key_exists('suspended', $payload) && (bool)$payload['suspended'];

    // Invalid dates must never reach nginx's static response or its config syntax.
    if (!pmssUserMovedDateIsValid($payload['movedUntil'] ?? null)) unset($payload['movedUntil']);

    foreach (['dockerEnabled' => true, 'lighttpdEnabled' => true, 'panelSessionLogin' => false, 'scheduledConfigBackup' => false] as $key => $default) {
        $payload[$key] = pmssUserConfigNormaliseToggleValue($payload, $key, $default);
    }

    // Safety gate: keep rootless Docker disabled for low-memory accounts.
    if (is_numeric($payload['ramMiB'] ?? null) && (int)$payload['ramMiB'] > 0
        && (int)$payload['ramMiB'] < pmssUserDockerMinRamMiB()) {
        $payload['dockerEnabled'] = false;
    }

    // Invariant: always write trafficLimit as 0.
    $payload['trafficLimit'] = 0;

    ksort($payload, SORT_STRING);
    return $payload;
}

/** Normalize only valid account names with array-valued records, preserving encounter order. */
function pmssUserConfigMapNormalise(array $users): array
{
    $normalised = [];
    foreach ($users as $name => $payload) {
        $name = (string) $name;
        if (!UserValidator::isValidUsername($name) || !is_array($payload)) {
            continue;
        }
        $normalised[$name] = pmssUserConfigPayloadNormalise($payload);
    }
    return $normalised;
}
