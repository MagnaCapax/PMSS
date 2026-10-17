<?php
/** Account-side AI launcher; deliberately independent of /scripts. */

const PMSS_AI_CODEX_BINARY = '/opt/pmss/ai-tools/codex/codex-rust-v0.93.0';
const PMSS_AI_CLAUDE_BINARY = '/opt/pmss/ai-tools/npm/claude/bin/claude';
const PMSS_AI_GEMINI_BINARY = '/opt/pmss/ai-tools/npm/gemini/bin/gemini';
const PMSS_AI_GEMINI_DEFAULTS = '/opt/pmss/ai-tools/gemini-virsilipas-defaults.json';

/** PHP 7.0 cannot enumerate getenv(); Linux exposes this process's environment. */
function pmssAiCodexEnvironment(): array
{
    if (PHP_VERSION_ID >= 70100) {
        return getenv();
    }
    $raw = @file_get_contents('/proc/self/environ');
    $env = [];
    foreach (explode("\0", is_string($raw) ? $raw : '') as $entry) {
        $pair = explode('=', $entry, 2);
        if (count($pair) === 2) {
            $env[$pair[0]] = $pair[1];
        }
    }
    return array_merge($env, $_ENV);
}

/** Read only assignments before the first TOML table. */
function pmssAiCodexHasTopLevelChoice(string $config): bool
{
    foreach (preg_split('/\r\n|\r|\n/', $config) as $line) {
        if (preg_match('/^\s*\[/', $line)) {
            break;
        }
        if (preg_match('/^\s*(?:model_provider|profile|model)\s*=/', $line)) {
            return true;
        }
    }
    return false;
}

/** Explicit CLI choices, including their separate and joined value forms, win. */
function pmssAiCodexHasCommandChoice(array $args): bool
{
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = $args[$i];
        if (in_array($arg, ['-p', '--profile', '--oss', '-m', '--model'], true)
            || preg_match('/^(?:--profile|--model)=|^-p.+|^-m.+/', $arg)) {
            return true;
        }
        if ($arg === '-c' || $arg === '--config' || strpos($arg, '--config=') === 0) {
            $value = strpos($arg, '--config=') === 0 ? substr($arg, 9) : ($args[++$i] ?? '');
            if (preg_match('/^\s*(?:model_provider|model\s*=)/', $value)) {
                return true;
            }
            continue;
        }
        if (strpos($arg, '-c') === 0 && strlen($arg) > 2) {
            if (preg_match('/^\s*(?:model_provider|model\s*=)/', substr($arg, 2))) {
                return true;
            }
            continue;
        }
        if ($arg === '--') {
            $arg = $args[$i + 1] ?? '';
        } elseif (isset($arg[0]) && $arg[0] === '-') {
            continue;
        }
        return in_array($arg, ['login', 'logout', 'completion', 'help'], true);
    }
    return false;
}

/** Pure precedence decision: return the executable arguments, environment and notice bit. */
function pmssAiCodexDecision(array $args, array $env, array $state): array
{
    $pass = ['argv' => array_merge([PMSS_AI_CODEX_BINARY], $args), 'env' => $env, 'notice' => false];
    if (in_array(strtolower((string) ($env['PMSS_VIRSILIPAS'] ?? '')), ['off', '0', 'no'], true)
        || ($state['uid'] ?? 0) === 0
        || !isset($env['HOME'])
        || $env['HOME'] === ''
        || empty($state['keyFileValid'])
        || !isset($state['key'], $state['endpoint'])
        || !is_string($state['key'])
        || $state['key'] === ''
        || preg_match('/[\s\p{Z}\p{C}]/u', $state['key']) !== 0
        || !is_string($state['endpoint'])
        || !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $state['endpoint'])
        || !empty($state['authExists'])
        || (isset($env['OPENAI_API_KEY']) && $env['OPENAI_API_KEY'] !== '')
        || (isset($env['OPENAI_BASE_URL']) && $env['OPENAI_BASE_URL'] !== '')
        || !empty($state['configChoice'])
        || pmssAiCodexHasCommandChoice($args)) {
        return $pass;
    }

    $config = 'model_providers.virsilipas={name="Pulsed Media AI credits",base_url="'
        .$state['endpoint'].'",env_key="VIRSILIPAS_API_KEY",wire_api="chat"}';
    $env['VIRSILIPAS_API_KEY'] = $state['key'];
    return [
        'argv' => array_merge([PMSS_AI_CODEX_BINARY, '-c', $config, '-c', 'model_provider="virsilipas"', '-c', 'model="default"'], $args),
        'env' => $env,
        'notice' => true,
    ];
}

/** Collect account-owned inputs without writing into the account. */
function pmssAiCodexState(array $env): array
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $home = $env['HOME'] ?? '';
    $state = ['uid' => $uid, 'keyFileValid' => false];
    if ($uid === 0 || $home === '') {
        return $state;
    }
    $file = rtrim($home, '/').'/.virsilipasKey';
    if (is_link($file) || !is_file($file) || @fileowner($file) !== $uid) {
        return $state;
    }
    $contents = @file_get_contents($file);
    if (!is_string($contents)) {
        return $state;
    }
    $settings = @parse_ini_string($contents, false, INI_SCANNER_RAW);
    if (!is_array($settings)) {
        return $state;
    }
    $state['keyFileValid'] = true;
    $state['key'] = $settings['key'] ?? '';
    $state['endpoint'] = $settings['endpoint'] ?? '';
    $codexHome = isset($env['CODEX_HOME']) && $env['CODEX_HOME'] !== '' ? $env['CODEX_HOME'] : rtrim($home, '/').'/.codex';
    $state['authExists'] = file_exists($codexHome.'/auth.json') || is_link($codexHome.'/auth.json');
    $config = @file_get_contents($codexHome.'/config.toml');
    $state['configChoice'] = is_string($config) && pmssAiCodexHasTopLevelChoice($config);
    // An unreadable existing config may carry an explicit choice; defer to it.
    if ($config === false && (file_exists($codexHome.'/config.toml') || is_link($codexHome.'/config.toml'))) {
        $state['configChoice'] = true;
    }
    return $state;
}

/** A personal Claude option, or unreadable state, takes precedence. */
function pmssAiClaudeFileChoice(string $path, string $kind): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return false;
    }
    if ($kind === 'credentials') {
        return true;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        return true;
    }
    $data = json_decode($contents, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        return true;
    }
    if ($kind === 'state') {
        return array_key_exists('oauthAccount', $data) || array_key_exists('primaryApiKey', $data);
    }
    if (array_key_exists('apiKeyHelper', $data)) {
        return true;
    }
    if (isset($data['env']) && is_array($data['env'])) {
        foreach ($data['env'] as $name => $value) {
            if (strpos((string) $name, 'ANTHROPIC_') === 0) {
                return true;
            }
        }
    }
    return false;
}

/** Read the account key and Claude's own settings without changing either. */
function pmssAiClaudeState(array $env): array
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $home = $env['HOME'] ?? '';
    $state = ['uid' => $uid, 'keyFileValid' => false, 'configChoice' => false];
    if ($uid === 0 || $home === '') {
        return $state;
    }
    $file = rtrim($home, '/').'/.virsilipasKey';
    if (is_link($file) || !is_file($file) || @fileowner($file) !== $uid) {
        return $state;
    }
    $contents = @file_get_contents($file);
    $settings = is_string($contents) ? @parse_ini_string($contents, false, INI_SCANNER_RAW) : false;
    if (!is_array($settings)) {
        return $state;
    }
    $state['keyFileValid'] = true;
    $state['key'] = $settings['key'] ?? '';
    $state['endpoint'] = $settings['endpoint'] ?? '';
    $custom = array_key_exists('CLAUDE_CONFIG_DIR', $env);
    $configDir = $custom ? $env['CLAUDE_CONFIG_DIR'] : rtrim($home, '/').'/.claude';
    $statePath = $custom ? rtrim($configDir, '/').'/.claude.json' : rtrim($home, '/').'/.claude.json';
    $state['configChoice'] = pmssAiClaudeFileChoice(rtrim($configDir, '/').'/.credentials.json', 'credentials')
        || pmssAiClaudeFileChoice($statePath, 'state')
        || pmssAiClaudeFileChoice(rtrim($configDir, '/').'/settings.json', 'settings');
    return $state;
}

/** Explicit command options are left for Claude Code to interpret. */
function pmssAiClaudeHasCommandChoice(array $args): bool
{
    foreach ($args as $arg) {
        if (in_array($arg, ['--model', '--fallback-model', '--settings', 'setup-token'], true)
            || preg_match('/^--(?:model|fallback-model|settings)=/', $arg)) {
            return true;
        }
    }
    return false;
}

/** Pure precedence decision; credentials enter only the child environment. */
function pmssAiClaudeDecision(array $args, array $env, array $state): array
{
    $pass = ['argv' => array_merge([PMSS_AI_CLAUDE_BINARY], $args), 'env' => $env, 'notice' => false];
    foreach (['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_BASE_URL',
        'CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_OAUTH_TOKEN'] as $name) {
        if (isset($env[$name]) && $env[$name] !== '') {
            return $pass;
        }
    }
    if (in_array(strtolower((string) ($env['PMSS_VIRSILIPAS'] ?? '')), ['off', '0', 'no'], true)
        || ($state['uid'] ?? 0) === 0
        || !isset($env['HOME']) || $env['HOME'] === ''
        || empty($state['keyFileValid']) || !isset($state['key'], $state['endpoint'])
        || !is_string($state['key']) || $state['key'] === ''
        || preg_match('/[\s\p{Z}\p{C}]/u', $state['key']) !== 0
        || !is_string($state['endpoint'])
        || !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $state['endpoint'])
        || !empty($state['configChoice']) || pmssAiClaudeHasCommandChoice($args)) {
        return $pass;
    }
    $endpoint = rtrim($state['endpoint'], '/');
    $env['ANTHROPIC_BASE_URL'] = preg_replace('#/v1$#', '', $endpoint);
    $env['ANTHROPIC_AUTH_TOKEN'] = $state['key'];
    foreach (['ANTHROPIC_MODEL', 'ANTHROPIC_DEFAULT_OPUS_MODEL', 'ANTHROPIC_DEFAULT_SONNET_MODEL',
        'ANTHROPIC_DEFAULT_HAIKU_MODEL', 'ANTHROPIC_SMALL_FAST_MODEL'] as $name) {
        if (!array_key_exists($name, $env)) {
            $env[$name] = 'default';
        }
    }
    return ['argv' => $pass['argv'], 'env' => $env, 'notice' => true];
}

/** An existing user auth selection, or unreadable settings, wins. */
function pmssAiGeminiSettingsChoice(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return false;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        return true;
    }
    $settings = json_decode($contents, true);
    if (!is_array($settings) || json_last_error() !== JSON_ERROR_NONE) {
        return true;
    }
    return isset($settings['security']['auth']) && is_array($settings['security']['auth'])
        && array_key_exists('selectedType', $settings['security']['auth']);
}

/** Gemini loads the nearest .env, preferring a trusted directory's .gemini file. */
function pmssAiGeminiEnvFile(string $cwd, string $home, bool $trusted, bool $ignoreLocal): string
{
    $dir = realpath($cwd);
    if (!is_string($dir)) {
        return '';
    }
    while (true) {
        if ($trusted && file_exists($dir.'/.gemini/.env')) {
            return $dir.'/.gemini/.env';
        }
        if (file_exists($dir.'/.env') && (!$ignoreLocal || $dir === $home)) {
            return $dir.'/.env';
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    if ($trusted && file_exists($home.'/.gemini/.env')) {
        return $home.'/.gemini/.env';
    }
    return file_exists($home.'/.env') ? $home.'/.env' : '';
}

/** Defer if any .env the CLI could select defines provider inputs. */
function pmssAiGeminiEnvChoice(string $cwd, string $home): bool
{
    foreach ([true, false] as $trusted) {
        foreach ([true, false] as $ignoreLocal) {
            $path = pmssAiGeminiEnvFile($cwd, $home, $trusted, $ignoreLocal);
            if ($path === '') {
                continue;
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                return true;
            }
            if (preg_match('/^\s*(?:export\s+)?(?:GEMINI_API_KEY|GOOGLE_API_KEY|GOOGLE_GEMINI_BASE_URL|GOOGLE_GENAI_USE_VERTEXAI|GOOGLE_CLOUD_PROJECT)\s*=/m', $contents)) {
                return true;
            }
        }
    }
    return false;
}

/** Read account state; no settings or credentials are written to the home. */
function pmssAiGeminiState(array $env): array
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $home = $env['HOME'] ?? '';
    $state = ['uid' => $uid, 'keyFileValid' => false, 'configChoice' => false];
    if ($uid === 0 || $home === '') {
        return $state;
    }
    $file = rtrim($home, '/').'/.virsilipasKey';
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || @fileowner($file) !== $uid) {
        return $state;
    }
    $contents = @file_get_contents($file);
    $settings = is_string($contents) ? @parse_ini_string($contents, false, INI_SCANNER_RAW) : false;
    if (!is_array($settings)) {
        return $state;
    }
    $state['keyFileValid'] = true;
    $state['key'] = $settings['key'] ?? '';
    $state['endpoint'] = $settings['endpoint'] ?? '';
    $base = !empty($env['GEMINI_CLI_HOME']) ? $env['GEMINI_CLI_HOME'] : $home;
    $config = rtrim($base, '/').'/.gemini';
    $state['configChoice'] = file_exists($config.'/oauth_creds.json') || is_link($config.'/oauth_creds.json')
        || file_exists($config.'/google_accounts.json') || is_link($config.'/google_accounts.json')
        || pmssAiGeminiSettingsChoice($config.'/settings.json')
        || pmssAiGeminiEnvChoice((string) getcwd(), $base);
    return $state;
}

/** Pure precedence decision: pass credentials only through the child environment. */
function pmssAiGeminiDecision(array $args, array $env, array $state): array
{
    $pass = ['argv' => array_merge([PMSS_AI_GEMINI_BINARY], $args), 'env' => $env, 'notice' => false];
    foreach (['GEMINI_API_KEY', 'GOOGLE_API_KEY', 'GOOGLE_GEMINI_BASE_URL',
        'GOOGLE_GENAI_USE_VERTEXAI', 'GOOGLE_GENAI_USE_GCA', 'GOOGLE_CLOUD_PROJECT',
        'GEMINI_CLI_AUTH_OVERRIDE', 'GEMINI_DEFAULT_AUTH_TYPE',
        'GEMINI_CLI_SYSTEM_DEFAULTS_PATH', 'GEMINI_CLI_SYSTEM_SETTINGS_PATH'] as $name) {
        if (isset($env[$name]) && $env[$name] !== '') {
            return $pass;
        }
    }
    foreach ($args as $arg) {
        if ($arg === '-m' || $arg === '--model' || strpos($arg, '--model=') === 0) {
            return $pass;
        }
    }
    if (in_array(strtolower((string) ($env['PMSS_VIRSILIPAS'] ?? '')), ['off', '0', 'no'], true)
        || ($state['uid'] ?? 0) === 0 || !isset($env['HOME']) || $env['HOME'] === ''
        || empty($state['keyFileValid']) || !isset($state['key'], $state['endpoint'])
        || !is_string($state['key']) || $state['key'] === ''
        || preg_match('/[\s\p{Z}\p{C}]/u', $state['key']) !== 0
        || !is_string($state['endpoint'])
        || !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $state['endpoint'])
        || !empty($state['configChoice'])) {
        return $pass;
    }
    $env['GOOGLE_GEMINI_BASE_URL'] = preg_replace('#/v1$#', '', rtrim($state['endpoint'], '/'));
    $env['GEMINI_API_KEY'] = $state['key'];
    $env['GEMINI_CLI_SYSTEM_DEFAULTS_PATH'] = PMSS_AI_GEMINI_DEFAULTS;
    return ['argv' => $pass['argv'], 'env' => $env, 'notice' => true];
}

/** Replace this process when possible; otherwise preserve all three streams. */
function pmssAiCodexExec(array $decision)
{
    $argv = $decision['argv'];
    $path = array_shift($argv);
    if (function_exists('pcntl_exec')) {
        @pcntl_exec($path, $argv, $decision['env']);
    }
    $command = escapeshellarg($path);
    foreach ($argv as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }
    $process = @proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $decision['env']);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Codex');
    }
    exit(proc_close($process));
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $args = array_slice($argv, 2);
    $env = pmssAiCodexEnvironment();
    $tool = $argv[1] ?? '';
    if (!in_array($tool, ['codex', 'claude', 'gemini'], true)) {
        exit(127);
    }
    $binary = $tool === 'codex' ? PMSS_AI_CODEX_BINARY : ($tool === 'claude' ? PMSS_AI_CLAUDE_BINARY : PMSS_AI_GEMINI_BINARY);
    $pass = ['argv' => array_merge([$binary], $args), 'env' => $env, 'notice' => false];
    try {
        $decision = $tool === 'codex' ? pmssAiCodexDecision($args, $env, pmssAiCodexState($env))
            : ($tool === 'claude' ? pmssAiClaudeDecision($args, $env, pmssAiClaudeState($env))
                : pmssAiGeminiDecision($args, $env, pmssAiGeminiState($env)));
    } catch (Throwable $e) {
        $decision = $pass;
    }
    if ($decision['notice'] && function_exists('posix_isatty') && posix_isatty(STDERR)) {
        $notice = $tool === 'codex'
            ? "Codex is using your Pulsed Media AI credits (~/.virsilipasKey). To change: PMSS_VIRSILIPAS=off codex, or codex login.\n"
            : ($tool === 'claude'
                ? "Claude Code is using your Pulsed Media AI credits (~/.virsilipasKey). To change: PMSS_VIRSILIPAS=off claude, or log in to your own account (PMSS_VIRSILIPAS=off claude, then /login).\n"
                : "Gemini CLI is using your Pulsed Media AI credits (~/.virsilipasKey). To change: PMSS_VIRSILIPAS=off gemini, or set your own GEMINI_API_KEY.\n");
        fwrite(STDERR, $notice);
    }
    try {
        pmssAiCodexExec($decision);
    } catch (Throwable $e) {
        if ($decision['notice']) {
            pmssAiCodexExec($pass);
        }
        exit(127);
    }
}
