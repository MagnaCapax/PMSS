<?php
/** Account-side Codex launcher; deliberately independent of /scripts. */

const PMSS_AI_CODEX_BINARY = '/opt/pmss/ai-tools/codex/codex-rust-v0.93.0';

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

/** Replace this process when possible; otherwise preserve all three streams. */
function pmssAiCodexExec(array $decision): void
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
    $pass = ['argv' => array_merge([PMSS_AI_CODEX_BINARY], $args), 'env' => $env, 'notice' => false];
    try {
        $decision = ($argv[1] ?? '') === 'codex' ? pmssAiCodexDecision($args, $env, pmssAiCodexState($env)) : $pass;
    } catch (Throwable $e) {
        $decision = $pass;
    }
    if ($decision['notice'] && function_exists('posix_isatty') && posix_isatty(STDERR)) {
        fwrite(STDERR, "Codex is using your Pulsed Media AI credits (~/.virsilipasKey). To change: PMSS_VIRSILIPAS=off codex, or codex login.\n");
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
