<?php
/** Prepare validated reconfiguration intent and persist it before service actions. @license GPL-3.0-only */

/** Retain legacy help, parser errors, account refusals and request precedence. */
function pmssUserConfigPrepare(?array $argv): array
{
    $usage = pmssUserConfigCliUsage()."\nIntel iGPU access:\n  --gpu-enabled=true|false  Persist Intel iGPU access (default: true).\n";
    $parsed = pmssParseCliTokens(pmssCliArgv($argv ?? null), array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled', 'bonus-quota-gib', 'gpu-enabled', 'lighttpd-min-threads'], pmssUserConfigCliResourceOptionNames()));
    $args = array_merge([''], $parsed['arguments']);
    if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
        echo $usage."\n";
        exit(0);
    }
    $welcomeMessage = pmssCliOption($parsed, 'welcome-message');
    $welcomeMessage = ($welcomeMessage === true || $welcomeMessage === null) ? null : (string) $welcomeMessage;
    try {
        $uploadThrottleKib = pmssUserConfigCliParseUploadThrottleOption(pmssCliOption($parsed, 'upload-throttle-kib'));
        $dockerEnabled = pmssUserConfigParseDockerEnabledOption(pmssCliOption($parsed, 'docker-enabled'));
        $gpuEnabled = pmssUserConfigParseGpuEnabledOption(pmssCliOption($parsed, 'gpu-enabled'));
    } catch (InvalidArgumentException $exception) {
        die($exception->getMessage()."\n");
    }
    try {
        $bonusQuotaGiB = pmssAddUserBonusQuotaGiBParse(pmssCliOption($parsed, 'bonus-quota-gib'));
        $lighttpdMinThreads = pmssUserConfigParseLighttpdMinThreadsOption(pmssCliOption($parsed, 'lighttpd-min-threads'));
    } catch (InvalidArgumentException $exception) {
        pmssCliExitWithStderr($exception->getMessage()."\n", 1);
    }
    $explicitResourceOverrides = pmssUserConfigCliResources($parsed, $args, 'userConfigIndex', 'explicit');
    $namedConfigChange = $uploadThrottleKib !== null
        || $dockerEnabled !== null
        || $gpuEnabled !== null
        || $bonusQuotaGiB !== null
        || $lighttpdMinThreads !== null
        || count($explicitResourceOverrides) > 0;
    $configMode = pmssUserConfigInvocationMode($args, $welcomeMessage !== null, $namedConfigChange);
    if ($configMode === '') {
        die($usage."\n");
    }

    // The $user array is populated from sanitized command-line arguments ($args)
    // provided by an operator or trusted automation.
    $user = array_merge([
        'name'      => pmssNormalizeUsername((string) $args[1]),
        'memory'    => (int) $args[2],
        'quota'     => (int) $args[3],
    ], pmssUserConfigCliResources($parsed, $args, 'userConfigIndex', 'resolved'));
    if ($bonusQuotaGiB !== null) {
        $user['bonusQuotaGiB'] = $bonusQuotaGiB;
    }

    $passwdEntry = pmssPasswdEntryLookup($user['name']);
    if ($passwdEntry === null) {
        die("No such user in passwd list\n");
    }

    $account = pmssUserAccountLookup($user['name']) ?? $passwdEntry;
    $user['id'] = (int) ($account['uid'] ?? 0);

    if ($user['id'] < 1000) {
        die("No system ID or user does not exist\n");
    }
    $expectedHome = "/home/{$user['name']}";
    if (rtrim((string) ($passwdEntry['dir'] ?? ($account['dir'] ?? '')), '/') !== $expectedHome || !file_exists($expectedHome)) {
        die("User does not exist\n");
    }

    $store = new UserConfigStore();
    $existing = $store->get($user['name']) ?? [];
    if ($configMode === 'welcome') {
        if (($error = pmssUserConfigWelcomeOnlyPersist($store, $user['name'], $expectedHome, $welcomeMessage, $existing)) !== null) {
            pmssCliExitWithStderr($error."\n", 1);
        }
        exit(0);
    }

    if ($configMode === 'named') {
        if (($error = pmssUserConfigBaselineError($existing, ['ramMiB', 'quota'])) !== null) {
            pmssCliExitWithStderr($error."\n", 1);
        }

        $user = pmssUserConfigNamedModeUser($user, $existing, $explicitResourceOverrides);
    }

    $payload = pmssUserConfigPayloadBuild($store, $existing, $user, array_fill_keys(array_keys($explicitResourceOverrides), true), $dockerEnabled, $gpuEnabled);
    if ($lighttpdMinThreads !== null) $payload['lighttpdMinThreads'] = $lighttpdMinThreads;
    if ($welcomeMessage !== null) {
        if (!pmssWelcomeUserMessageSet($user['name'], $expectedHome, $welcomeMessage)) {
            fwrite(STDERR, "Warning: failed to persist welcome message for {$user['name']}\n");
        }
        unset($payload['welcomeMessage']);
    }

    // The root store holds the settings root jobs apply (ADR 0213); write it before any home copy.
    if ($uploadThrottleKib !== null) {
        if ($uploadThrottleKib > 0) $payload['uploadThrottleKib'] = $uploadThrottleKib;
        else unset($payload['uploadThrottleKib']);
    }
    if (!$store->persist($user['name'], $payload)) {
        if (function_exists('pmssUserLog')) pmssUserLog($user['name'], 'userConfig: user store write failed; no settings applied');
        pmssCliExitWithStderr("Error: failed to persist user config for {$user['name']}\n", 1);
    }
    // Retry explicit requests even when unchanged, so a failed refresh can converge.
    if ($lighttpdMinThreads !== null
        && runStep('Configuring lighttpd worker floor', pmssBuildCommand('/scripts/util/userConfigLighttpd.php', ['--strict', $user['name']])) !== 0) {
        pmssCliExitWithStderr("Error: lighttpd config refresh failed; worker floor saved, rerun userConfig.php\n", 1);
    }
    pmssUserConfigApplyGpuPolicy($user['name'], $existing, $payload);
    // Display copy of the upload cap for the account's panel and tools.
    if ($uploadThrottleKib !== null) {
        if (!pmssWriteTorrentThrottle($user['name'], $uploadThrottleKib)) {
            fwrite(STDERR, "Warning: failed to write torrent upload throttle display copy for {$user['name']}\n");
        }
    }

    return compact('user', 'expectedHome', 'configMode', 'store', 'payload');
}
