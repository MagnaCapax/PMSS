<?php
/** Account-local Virsilipas metadata; provider credentials never enter config-set argv. */

/** Describe the endpoint's text-only input and streamed usage contract. */
function ocVirsilipasModel(): array
{
    return array('id' => 'default', 'name' => 'default (Custom Provider)',
        'input' => array('text'), 'compat' => array('supportsUsageInStreaming' => true));
}

/** Read configuration without invoking a CLI that could print resolved secrets. */
function ocConfigRead(array $paths): array
{
    $file = $paths['state'].'/openclaw.json';
    $raw = !is_link($file) && is_file($file) ? @file_get_contents($file) : false;
    $config = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($config)) throw new RuntimeException('Could not read OpenClaw configuration');
    return $config;
}

/** Patch only PMSS's single default model; leave the provider object and SecretRef untouched. */
function ocVirsilipasModelApply(array $paths, array $env, bool $onboarded = false): bool
{
    $marker = $paths['state'].'/pmss-virsilipas';
    if (!$onboarded && (!is_file($marker) || is_link($marker))) return false;
    $credentials = ocVirsilipasCredentials($paths['home']);
    if ($credentials === null) return false;
    $config = ocConfigRead($paths);
    $provider = $config['models']['providers']['virsilipas'] ?? null;
    if (!is_array($provider) || ($provider['baseUrl'] ?? null) !== $credentials['endpoint']
        || ($provider['api'] ?? null) !== 'openai-completions'
        || !isset($provider['models']) || !is_array($provider['models'])
        || count($provider['models']) !== 1 || ($provider['models'][0]['id'] ?? null) !== 'default'
        || ($provider['apiKey'] ?? null) != array('source' => 'env', 'provider' => 'default', 'id' => 'CUSTOM_API_KEY')) {
        return false;
    }
    $models = $provider['models'];
    $models[0]['input'] = array('text');
    if (isset($models[0]['compat']) && !is_array($models[0]['compat'])) return false;
    $models[0]['compat']['supportsUsageInStreaming'] = true;
    if ($models === $provider['models']) return false;
    list($rc) = ocCli($paths, array('config', 'set', 'models.providers.virsilipas.models', json_encode($models)), $env);
    if ($rc !== 0) throw new RuntimeException('Could not configure Virsilipas model metadata');
    return true;
}
