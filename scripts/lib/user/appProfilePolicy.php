<?php
/** Pure profile lookup shared by CLI validation and creation policy. @license GPL-3.0-only */

/** Read the operator-owned profile definitions. */
function pmssUserAppProfiles(): array
{
    return require __DIR__.'/appProfiles.php';
}

/** Resolve the omitted option and reject unknown or malformed lookup keys before creation. */
function pmssUserAppProfileName($name = null): string
{
    $name = $name ?? 'default';
    if (!is_string($name) || preg_match('/^[a-z0-9-]{1,40}$/D', $name) !== 1
        || !array_key_exists($name, pmssUserAppProfiles())) {
        throw new InvalidArgumentException('Invalid --app-profile; expected '.implode(', ', array_keys(pmssUserAppProfiles())));
    }
    return $name;
}

/** Return only the selected definition, never a path or command derived from input. */
function pmssUserAppProfileApps(array $user): array
{
    return pmssUserAppProfiles()[pmssUserAppProfileName($user['appProfile'] ?? null)];
}
