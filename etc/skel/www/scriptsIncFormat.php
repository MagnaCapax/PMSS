<?php
/** Format helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssFormatBytes')) {
/**
  * Format byte counts with binary IEC units for customer-facing pages.
  */
 function pmssFormatBytes($bytes, $precision = 1, $minimumUnitIndex = 0, $trimTrailingZeros = false) {
  $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
  $bytes = max((float) $bytes, 0.0);
  $index = 0;
  $minimumUnitIndex = max(0, min((int) $minimumUnitIndex, count($units) - 1));
  while ($index < $minimumUnitIndex && $index < count($units) - 1) {
   $bytes /= 1024.0;
   $index++;
  }
  while ($bytes >= 1024 && $index < count($units) - 1) {
   $bytes /= 1024.0;
   $index++;
  }

  $formatted = number_format($bytes, (int) $precision, '.', '');
  if ($trimTrailingZeros) {
   $formatted = rtrim(rtrim($formatted, '0'), '.');
  }

  return ($formatted === '' ? '0' : $formatted).' '.$units[$index];
 }
}

if (!function_exists('pmssWelcomeSerializedArrayHasObject')) {
/**
  * Reject object payloads that should never appear in customer snapshots.
  */
 function pmssWelcomeSerializedArrayHasObject($value, $depth = 0) {
  if (is_object($value)) return true;
  if (!is_array($value)) return false;
  if ($depth > 32 || count($value) > 256) return true;
  foreach ($value as $child) {
   if (pmssWelcomeSerializedArrayHasObject($child, $depth + 1)) return true;
  }
  return false;
 }
}

if (!function_exists('pmssWelcomeSerializedArrayDecode')) {
/**
  * Decode serialized customer-facing arrays without object wakeups.
  */
 function pmssWelcomeSerializedArrayDecode($raw, $maxBytes = 8192) {
  if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) return null;
  $data = @unserialize($raw, array('allowed_classes' => false));
  return is_array($data) && !pmssWelcomeSerializedArrayHasObject($data) ? $data : null;
 }
}

if (!function_exists('pmssJsonDecodeAssoc')) {
/** Decode JSON through associative arrays, rejecting invalid or scalar payloads. */
 function pmssJsonDecodeAssoc($payload) { $decoded = json_decode((string) $payload, true); return is_array($decoded) ? $decoded : null; }
}

if (!function_exists('pmssJsonEncodePretty')) {
/** Encode data with PMSS's standard pretty file-output flags. */
 function pmssJsonEncodePretty($payload, $extraFlags = 0) { $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | (int) $extraFlags); return is_string($encoded) ? $encoded : null; }
}

if (!function_exists('pmssCustomerHtmlAttr')) {
/** Escape customer GUI text and attributes with the shared PMSS flags. */ function pmssCustomerHtmlAttr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('pmssCustomerContextualHelpLinkBuild')) {
/** Render help only for panel items with a known published wiki page. */
 function pmssCustomerContextualHelpLinkBuild($item) {
  $targets = array(
   'panelFrameSource' => array(
    'url' => 'https://wiki.pulsedmedia.com/index.php/Panel_Frame_Source',
    'title' => 'Read about panel frame sources in the Pulsed Media Wiki',
   ),
  );
  if (!is_string($item) || !isset($targets[$item])) return '';

  $target = $targets[$item];
  return ' <a class="pmss-contextual-help" href="'.pmssCustomerHtmlAttr($target['url']).'" target="_blank" rel="noopener noreferrer" title="'.pmssCustomerHtmlAttr($target['title']).'" aria-label="'.pmssCustomerHtmlAttr($target['title']).'">(?)</a>';
 }
}

if (!function_exists('pmssWelcomeHttpContextCreate')) {
/**
  * Build the standard remote-request context used by PMSS GUI pages.
  */
 function pmssWelcomeHttpContextCreate() {
  return stream_context_create(array('http' => array('timeout' => 5, 'follow_location' => 0, 'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)')));
 }
}
