<?php
/** In-memory service-bootstrap dependencies; never accesses host processes or services. */
namespace ServiceBootstrapCharacterization;

function logMessage(string $message): void { $GLOBALS['trace'][] = ['log', $message]; }
function pmssEnvFlagEnabled(string $key): bool { return $GLOBALS['dryRun']; }
function runStep(string $description, string $command): int
{
    $GLOBALS['trace'][] = ['command', $description, $command];
    return array_shift($GLOBALS['returnCodes']);
}
function file_get_contents(string $path)
{
    $GLOBALS['trace'][] = ['read', $path];
    return $GLOBALS['files'][$path] ?? false;
}
function file_put_contents(string $path, string $content)
{
    $GLOBALS['trace'][] = ['write', $path, $content];
    if ($GLOBALS['writeResult'] === false) return false;
    if ($GLOBALS['readback']) $GLOBALS['files'][$path] = $content;
    return $GLOBALS['writeResult'];
}
function scandir(string $path): array
{
    $GLOBALS['trace'][] = ['scan', $path];
    return $GLOBALS['entries'];
}
function is_dir(string $path): bool { return in_array($path, $GLOBALS['directories'], true); }
function pmssSshdConfigWriteUpdated(string $path, string $original, string $updated, string $label): bool
{
    $GLOBALS['trace'][] = ['config', $path, $original, $updated, $label];
    return $GLOBALS['configWrite'];
}
