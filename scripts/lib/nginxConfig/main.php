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

function pmssCreateNginxConfigMain(array $argv): int
{
    $usage = <<<TXT
Usage:
  /scripts/util/createNginxConfig.php [--user USERNAME] [--restart]
  /scripts/util/createNginxConfig.php USERNAME [--restart]

Options:
  --user, -u USERNAME  Only regenerate nginx config for USERNAME (keeps other user configs intact)
  --restart, -r        Restart nginx after writing configs (only if config test passes)
  --help, -h           Show this help

TXT;

    $parsed = pmssParseCliTokens($argv);
    if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
        echo $usage;
        return 0;
    }

    $requestedUser = pmssNormalizeUsername((string) pmssCliOption($parsed, 'user', 'u', ''));
    $positionals = $parsed['arguments'] ?? [];
    if ($requestedUser === '' && count($positionals) === 1) {
        $requestedUser = pmssNormalizeUsername((string) $positionals[0]);
    } elseif (count($positionals) > 1) {
        fwrite(STDERR, $usage);
        return 1;
    }

    $restartNginx = pmssCliOption($parsed, 'restart', 'r', false) !== false;

    if ($requestedUser !== '' && pmssUsernameNormalizeIfValid($requestedUser) === null) {
        fwrite(STDERR, "Invalid username: {$requestedUser}\n");
        return 1;
    }

    $users = pmssListManagedUsers('/scripts/listUsers.php');
    if ($users === []) {
        echo "No users setup - nothing to do\n";
        return 0;
    }
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);

    $singleUser = ($requestedUser !== '');
    if ($singleUser) {
        if (!in_array($requestedUser, $users, true)) {
            fwrite(STDERR, "Username not found: {$requestedUser}\n");
            return 1;
        }
        $users = [$requestedUser];
    }

    $ctx = pmssCreateNginxConfigSetup($requestedUser, $singleUser);
    foreach ($users as $thisUser) {
        pmssCreateNginxConfigGenerateUser($thisUser, $ctx, $singleUser);
    }

    $subdomainConfigDir = (string)($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d');
    // Permission hardening for generated nginx configs.
    // Disallow config reading by anyone else.
    if (glob('/etc/nginx/users/*')) {
        passthru('chmod 640 /etc/nginx/users/*');
    }
    if (glob($subdomainConfigDir.'/pmss-user-*.conf')) {
        passthru('chmod 640 '.$subdomainConfigDir.'/pmss-user-*.conf');
    }
    passthru('chmod 640 /etc/nginx/*.conf');

    return pmssCreateNginxConfigTestAndMaybeRestart($restartNginx);
}
