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
pmssRequireRelativeFiles(__DIR__, ['lib/user/userConfigStore.php', 'lib/user/UserValidator.php', 'lib/userLifecycle.php']);

requireRoot();

$usage = <<<USAGE
Usage:
  userSetting.php list
  userSetting.php view USER
  userSetting.php get USER KEY
  userSetting.php set USER KEY VALUE
  userSetting.php unset USER KEY

USAGE;

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
    exit(pmssJsonEmitPayload($payload, 'Failed to encode user settings JSON.', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        exit(pmssJsonEmitPayload($value, 'Failed to encode user setting JSON.', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    echo $value."\n";
    exit(0);
}

if ($action === 'unset' || $action === 'set') {
    $statusMessage = ($action === 'set') ? 'setting_updated' : 'setting_removed';
    if ($action === 'set') {
        $value = $argv[4] ?? null;
        if ($value === null) {
            fwrite(STDERR, $usage);
            exit(1);
        }
        $payload[$key] = $value;
    } elseif (array_key_exists($key, $payload)) {
        unset($payload[$key]);
    }
    $saved = $store->persist($user, $payload);
    pmssUserLifecycleContextLogStatusMessage(
        'settings',
        $action,
        $user,
        $saved ? 'OK' : 'ERR',
        $saved ? $statusMessage : 'failed_to_save',
        array('key' => $key)
    );
    if (!$saved) {
        fwrite(STDERR, "Failed to save settings\n");
        exit(1);
    }
    echo "OK\n";
    exit(0);
}

fwrite(STDERR, $usage);
exit(1);
