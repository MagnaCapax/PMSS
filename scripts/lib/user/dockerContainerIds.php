<?php
/**
 * Validation for explicit rootless Docker container start requests.
 *
 * @license GPL-3.0-only
 */

function pmssUserDockerContainerIdsValid(array $ids): bool
{
    if (count($ids) < 1 || count($ids) > 500) {
        return false;
    }
    foreach ($ids as $id) {
        if (!is_string($id) || preg_match('/^[0-9a-f]{64}$/D', $id) !== 1) {
            return false;
        }
    }
    return true;
}
