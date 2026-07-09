<?php
/**
 * Nginx config generation entrypoint used by scripts/util/createNginxConfig.php.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../userLifecycle.php';
pmssRequireRelativeFiles(__DIR__, ['../cli/optionParser.php', '../nginxUserHosts.php', 'setup.php', 'userConfigsGenerate.php', 'configTest.php']);

/**
 * Apply chmod to regular files matched by a glob without invoking a shell.
 *
 * This keeps permission hardening aligned with historical intent while
 * refusing symlinked or non-file targets that should never appear in generated
 * nginx config paths.
 */
function pmssCreateNginxConfigChmodGlob(int $mode, string $pattern): void
{
    $matches = glob($pattern);
    if (!is_array($matches) || $matches === array()) {
        return;
    }

    sort($matches, SORT_STRING);
    foreach ($matches as $path) {
        if (is_link($path) || !is_file($path)) {
            fwrite(STDERR, '[WARN] Skipping unsafe nginx chmod target: '.$path.PHP_EOL);
            continue;
        }
        if (!@chmod($path, $mode)) {
            fwrite(STDERR, sprintf('[WARN] Failed to chmod %04o nginx config target: %s', $mode, $path).PHP_EOL);
            continue;
        }
        clearstatcache(true, $path);
    }
}

function pmssCreateNginxConfigMain(array $argv): int
{
    $usage = pmssCliHelpUsageOptions([
        '/scripts/util/createNginxConfig.php [--user USERNAME] [--restart]',
        '/scripts/util/createNginxConfig.php USERNAME [--restart]',
    ], [
        ['--user, -u USERNAME', 'Only regenerate nginx config for USERNAME (keeps other user configs intact)'],
        ['--restart, -r', 'Restart nginx after writing configs (only if config test passes)'],
    ], 21, [], true, ['--help, -h', 'Show this help']);

    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage)) === null) return 0;

    $requestedUser = (string) pmssCliOption($parsed, 'user', 'u', '');
    $positionals = $parsed['arguments'] ?? [];
    if ($requestedUser === '' && count($positionals) === 1) {
        $requestedUser = (string) $positionals[0];
    } elseif (count($positionals) > 1) {
        return pmssCliReturnWithStderr($usage);
    }

    $restartNginx = pmssCliOptionPresent($parsed, 'restart', 'r');

    $selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $requestedUser, array('emitEmptyMessage' => true, 'invalidMessage' => "Invalid username: %s\n", 'notFoundMessage' => "Username not found: %s\n"));
    if ($selection['exitCode'] !== 0 || $selection['users'] === array()) return $selection['exitCode'];

    $requestedUser = $selection['username'];
    $users = $selection['users'];
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);

    $singleUser = ($requestedUser !== '');

    $ctx = pmssCreateNginxConfigSetup($requestedUser, $singleUser);
    foreach ($users as $thisUser) {
        pmssCreateNginxConfigGenerateUser($thisUser, $ctx, $singleUser);
    }

    $subdomainConfigDir = (string)($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d');
    // Permission hardening for generated nginx configs.
    // Disallow config reading by anyone else.
    pmssCreateNginxConfigChmodGlob(0640, '/etc/nginx/users/*');
    pmssCreateNginxConfigChmodGlob(0640, $subdomainConfigDir.'/pmss-user-*.conf');
    pmssCreateNginxConfigChmodGlob(0640, '/etc/nginx/*.conf');

    return pmssCreateNginxConfigTestAndMaybeRestart($restartNginx);
}
