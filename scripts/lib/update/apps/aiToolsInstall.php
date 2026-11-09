<?php
/**
 * Update app installer: system-wide AI CLI tools.
 *
 * Installs Gemini CLI, Codex CLI, and Claude Code under `/opt/pmss/ai-tools`
 * and links commands into `/usr/local/bin` so all users can run them without
 * consuming per-user quota. Credentials remain user-scoped in home directories.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/remoteBinary.php';
require_once __DIR__.'/aiToolsCodex.php';
require_once __DIR__.'/aiToolsClaude.php';
require_once __DIR__.'/aiToolsGemini.php';
require_once __DIR__.'/../rootOwnedTree.php';

pmssRequireRelativeFiles(__DIR__.'/aiTools', ['node.php', 'npm.php', 'codexInstall.php']);

$logger = 'logmsg';
$force = pmssEnvFlagEnabled('PMSS_FORCE_AI_TOOLS_REFRESH');
$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
$supportsPinnedArtifacts = pmssPinnedRemoteAmd64ArtifactsSupported(php_uname('m'));
$nodeBinary = pmssAiToolsNodeRuntimeResolve($dryRun, $supportsPinnedArtifacts, $logger);
pmssAiToolsNpmInstall($nodeBinary, $force, $dryRun, $logger);
pmssAiToolsCodexInstall($force, $dryRun, $supportsPinnedArtifacts, $logger);
