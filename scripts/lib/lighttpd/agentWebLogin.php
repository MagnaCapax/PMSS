<?php
/**
 * Optional agent web login on an account's /user-<account>/ area (ADR 0151).
 *
 * Each account has one deterministic agent login name. PMSS only names it in
 * the account's lighttpd require rule, and only on a host whose operator
 * created the marker; PMSS never writes a password line for it. The support
 * tool that adds and removes that short-lived htpasswd line derives the same
 * name itself, so this file is the one definition of the derivation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/../pathSafety.php';

/** Fixed login-name prefix (UTF-8), followed by 20 letters from pmssAgentWebLoginName(). */
const PMSS_AGENT_WEB_LOGIN_PREFIX = 'pm-väinämöinen-';

/** Operator-set host marker; absent by default (no file of this name is shipped). */
function pmssAgentWebLoginMarkerPath(): string
{
    return pmssResolvePathFromEnv('PMSS_AGENT_WEB_LOGIN_MARKER', '/etc/seedbox/config/agentWebLogin.enabled');
}

/** True when the operator has enabled the agent login on this host. */
function pmssAgentWebLoginEnabled(?string $markerPath = null): bool
{
    return is_file($markerPath ?? pmssAgentWebLoginMarkerPath());
}

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

/** Text appended to the /user- zone's require rule: '' unless the host opted in. */
function pmssAgentWebLoginRequireSuffix(string $user, ?string $markerPath = null): string
{
    $name = pmssAgentWebLoginName($user);

    return $name !== '' && pmssAgentWebLoginEnabled($markerPath) ? '|user='.$name : '';
}
