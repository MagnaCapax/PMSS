<?php
/**
 * Agent web login on an account's /user-<account>/ area (ADR 0151).
 *
 * Each account has one deterministic agent login name. PMSS only names it in
 * the account's lighttpd require rule; PMSS never writes a password line for
 * it, so the name admits no one until such a line exists. The support
 * tool that adds and removes that short-lived htpasswd line derives the same
 * name itself, so this file is the one definition of the derivation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';

/** Fixed login-name prefix (UTF-8), followed by 20 letters from pmssAgentWebLoginName(). */
const PMSS_AGENT_WEB_LOGIN_PREFIX = 'pm-väinämöinen-';

/**
 * The account's agent login name, or '' for an invalid account name.
 *
 * Same input as the per-account ids in nginxConfig/pikkumiesUi.php
 * (hash('sha256', $user)): the first 20 hex digits, each mapped 0-f to A-P.
 */
function pmssAgentWebLoginName(string $user): string
{
    if (!pmssUsernameIsValid($user)) {
        return '';
    }

    return PMSS_AGENT_WEB_LOGIN_PREFIX.strtr(substr(hash('sha256', $user), 0, 20), '0123456789abcdef', 'ABCDEFGHIJKLMNOP');
}

/** Text appended to the /user- zone's require rule: '' for an invalid account name. */
function pmssAgentWebLoginRequireSuffix(string $user): string
{
    $name = pmssAgentWebLoginName($user);

    return $name !== '' ? '|user='.$name : '';
}
