#!/usr/bin/env php
<?php
/**
 * PMSS user setting inspector/editor.
 *
 * Root-only CLI helper to view or edit per-user config stored under:
 *   /etc/seedbox/config/users/<username>.json
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/runtime.php';
require_once __DIR__.'/lib/user/userConfigStore.php';
require_once __DIR__.'/lib/user/UserValidator.php';
require_once __DIR__.'/lib/userLifecycle.php';

requireRoot();

$usage = <<<USAGE
Usage:
  userSetting.php list
  userSetting.php view USER
  userSetting.php get USER KEY
  userSetting.php set USER KEY VALUE
  userSetting.php unset USER KEY

USAGE;

/**
 * Emit a structured log record for settings operations.
 */
function pmssUserSettingLog(string $user, string $phase, string $status, string $message, array $extra = array()): void
{
    pmssUserLifecycleContextLog(
        'settings',
        $phase,
        $user,
        array_merge(
            array(
                'status'  => $status,
                'message' => $message,
            ),
            $extra
        )
    );
}

$action = $argv[1] ?? '';
if ($action === '' || $action === '--help' || $action === '-h') {
    echo $usage;
    exit(0);
}

$store = new UserConfigStore();

if ($action === 'list') {
    foreach (array_keys($store->loadAll()) as $name) {
        echo $name."\n";
    }
    exit(0);
}

$user = pmssNormalizeUsername(trim((string) ($argv[2] ?? '')));
if ($user === '' || !UserValidator::isValidUsername($user)) {
    fwrite(STDERR, "Invalid username\n");
    exit(1);
}

if ($action === 'view') {
    $payload = $store->get($user);
    if (!is_array($payload)) {
        fwrite(STDERR, "User not found\n");
        exit(1);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

$key = $argv[3] ?? '';
if ($key === '') {
    fwrite(STDERR, $usage);
    exit(1);
}

$payload = $store->get($user);
if (!is_array($payload)) {
    fwrite(STDERR, "User not found\n");
    exit(1);
}

if ($action === 'get') {
    if (!array_key_exists($key, $payload)) {
        fwrite(STDERR, "Key not found\n");
        exit(1);
    }
    $value = $payload[$key];
    if (is_array($value)) {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    } else {
        echo $value."\n";
    }
    exit(0);
}

if ($action === 'unset') {
    if (array_key_exists($key, $payload)) {
        unset($payload[$key]);
    }
    if (!$store->set($user, $payload)) {
        pmssUserSettingLog($user, 'unset', 'ERR', 'failed_to_save', array('key' => $key));
        fwrite(STDERR, "Failed to save settings\n");
        exit(1);
    }
    $store->writeUserCache($user, $payload);
    pmssUserSettingLog($user, 'unset', 'OK', 'setting_removed', array('key' => $key));
    echo "OK\n";
    exit(0);
}

if ($action === 'set') {
    $value = $argv[4] ?? null;
    if ($value === null) {
        fwrite(STDERR, $usage);
        exit(1);
    }
    $payload[$key] = $value;
    if (!$store->set($user, $payload)) {
        pmssUserSettingLog($user, 'set', 'ERR', 'failed_to_save', array('key' => $key));
        fwrite(STDERR, "Failed to save settings\n");
        exit(1);
    }
    $store->writeUserCache($user, $payload);
    pmssUserSettingLog($user, 'set', 'OK', 'setting_updated', array('key' => $key));
    echo "OK\n";
    exit(0);
}

fwrite(STDERR, $usage);
exit(1);
