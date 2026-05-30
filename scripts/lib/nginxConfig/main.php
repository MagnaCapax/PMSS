<?php
/**
 * Nginx config generation entrypoint used by scripts/util/createNginxConfig.php.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/../nginxUserHosts.php';
require_once __DIR__.'/setup.php';
require_once __DIR__.'/userConfigsGenerate.php';
require_once __DIR__.'/configTest.php';

/**
 * Apply chmod to files matched by a glob without relying on shell expansion.
 *
 * This keeps permission hardening aligned with historical intent while
 * quoting every path before it reaches the shell.
 */
function pmssCreateNginxConfigChmodGlob(int $mode, string $pattern): void
{
    $matches = glob($pattern);
    if (!is_array($matches) || $matches === array()) {
        return;
    }

    sort($matches, SORT_STRING);
    $quotedPaths = array_map('escapeshellarg', $matches);
    passthru(sprintf('chmod %o %s', $mode, implode(' ', $quotedPaths)));
}

function pmssCreateNginxConfigMain(array $argv): int
{
    $usage = pmssCliHelpUsageOptions([
        '/scripts/util/createNginxConfig.php [--user USERNAME] [--restart]',
        '/scripts/util/createNginxConfig.php USERNAME [--restart]',
    ], [
        ['--user, -u USERNAME', 'Only regenerate nginx config for USERNAME (keeps other user configs intact)'],
        ['--restart, -r', 'Restart nginx after writing configs (only if config test passes)'],
        ['--help, -h', 'Show this help'],
    ], 21);

    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage)) === null) return 0;

    $requestedUser = (string) pmssCliOption($parsed, 'user', 'u', '');
    $positionals = $parsed['arguments'] ?? [];
    if ($requestedUser === '' && count($positionals) === 1) {
        $requestedUser = (string) $positionals[0];
    } elseif (count($positionals) > 1) {
        fwrite(STDERR, $usage);
        return 1;
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
