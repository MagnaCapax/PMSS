<?php
/** Render a static moved notice without customer files or request input. */

require_once __DIR__.'/../user/userConfigStore.php';

/** Quote trusted HTML for an nginx string after validating its sole data value. */
function pmssNginxMovedPage(string $template, string $until): string
{
    if ($template === '' || !pmssUserMovedDateIsValid($until)) {
        throw new RuntimeException('Moved notice requires a template and valid removal date');
    }
    $html = str_replace('##movedUntil', $until, $template);
    return str_replace(['\\', "'", '$'], ['\\\\', "\\'", '\\$'], $html);
}
