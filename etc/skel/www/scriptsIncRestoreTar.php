<?php
/** RestoreTar helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerBackupRestoreTarSize')) {
/** Parse a POSIX tar octal size field. */
 function pmssCustomerBackupRestoreTarSize($raw) {
  $raw = trim((string) $raw, " \0");
  if ($raw === '' || preg_match('/\A[0-7]+\z/', $raw) !== 1) return null;
  return octdec($raw);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarSkipPayload')) {
/** Advance a gzipped tar stream over one padded payload. */
 function pmssCustomerBackupRestoreTarSkipPayload($handle, $size) {
  return pmssCustomerBackupRestoreTarBlocksRead($handle, max(0, (int) $size), false);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarPayloadRead')) {
/** Read a bounded tar metadata payload and consume its padding. */
 function pmssCustomerBackupRestoreTarPayloadRead($handle, $size) {
  if ((int) $size < 0 || (int) $size > 65536) return false;
  return pmssCustomerBackupRestoreTarBlocksRead($handle, (int) $size, true);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarBlocksRead')) {
/** Consume padded blocks once after caller validation, retaining only requested metadata. */
 function pmssCustomerBackupRestoreTarBlocksRead($handle, $size, $capture) {
  $remaining = (int) (ceil((int) $size / 512) * 512);
  $raw = '';
  while ($remaining > 0) {
   $chunk = @gzread($handle, min(8192, $remaining));
   if (!is_string($chunk) || $chunk === '') return false;
   $remaining -= strlen($chunk);
   if ($capture) $raw .= $chunk;
  }
  return $capture ? substr($raw, 0, (int) $size) : true;
 }
}

if (!function_exists('pmssCustomerBackupRestorePaxPayloadParse')) {
/** Parse the small subset of PAX headers that can override member paths. */
 function pmssCustomerBackupRestorePaxPayloadParse($payload) {
  $headers = array();
  $offset = 0;
  $length = strlen((string) $payload);
  while ($offset < $length) {
   $space = strpos($payload, ' ', $offset);
   if ($space === false) break;
   $recordLength = (int) substr($payload, $offset, $space - $offset);
   if ($recordLength <= 0 || $offset + $recordLength > $length) break;

   $record = substr($payload, $space + 1, $recordLength - ($space + 1 - $offset));
   $equals = strpos($record, '=');
   if ($equals !== false) {
    $headers[substr($record, 0, $equals)] = rtrim(substr($record, $equals + 1), "\n");
   }
   $offset += $recordLength;
  }
  return $headers;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberNormalize')) {
/** Normalize one archive member name, returning an empty string for unsafe paths. */
 function pmssCustomerBackupRestoreMemberNormalize($member) {
  $member = is_string($member) ? str_replace('\\', '/', $member) : '';
  if ($member === '' || strpos($member, "\0") !== false || strpos($member, '/') === 0) return '';
  while (strpos($member, './') === 0) {
   $member = substr($member, 2);
  }

  $parts = array();
  foreach (explode('/', $member) as $part) {
   if ($part === '' || $part === '.') continue;
   if ($part === '..') return '';
   $parts[] = $part;
  }
  return $parts === array() ? '' : implode('/', $parts);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarMemberTypesRead')) {
/** Read tar headers so link members can be skipped before Phar extraction. */
 function pmssCustomerBackupRestoreTarMemberTypesRead($archivePath) {
  $handle = @gzopen($archivePath, 'rb');
  if (!is_resource($handle)) return null;

  $types = array();
  $pendingName = null;
  while (!@gzeof($handle)) {
   $header = @gzread($handle, 512);
   if (!is_string($header) || $header === '') break;
   if (strlen($header) !== 512) { @gzclose($handle); return null; }
   if (trim($header, "\0") === '') break;

   $type = substr($header, 156, 1);
   $type = ($type === '' || $type === "\0") ? '0' : $type;
   $size = pmssCustomerBackupRestoreTarSize(substr($header, 124, 12));
   if ($size === null) { @gzclose($handle); return null; }

   $name = rtrim(substr($header, 0, 100), " \0");
   $prefix = rtrim(substr($header, 345, 155), " \0");
   if ($prefix !== '') $name = $prefix.'/'.$name;

   // GNU long names and PAX paths share the bounded metadata read and advance.
   if ($type === 'L' || $type === 'x') {
    $payload = pmssCustomerBackupRestoreTarPayloadRead($handle, $size);
    if (!is_string($payload)) { @gzclose($handle); return null; }
    if ($type === 'L') $pendingName = rtrim($payload, "\0");
    else {
     $pax = pmssCustomerBackupRestorePaxPayloadParse($payload);
     if (isset($pax['path'])) $pendingName = $pax['path'];
    }
    continue;
   }

   if (!pmssCustomerBackupRestoreTarSkipPayload($handle, $size)) { @gzclose($handle); return null; }
   $member = pmssCustomerBackupRestoreMemberNormalize($pendingName !== null ? $pendingName : $name);
   $pendingName = null;
   if ($member !== '') {
    $currentType = pmssCustomerBackupRestoreMemberTypeAllowed($type) ? $type : '!';
    $types[$member] = isset($types[$member]) && $types[$member] !== $currentType ? '!' : $currentType;
   }
  }

  @gzclose($handle);
  return $types;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberAllowed')) {
/** Keep restore extraction exactly under the existing backup allowlist. */
 function pmssCustomerBackupRestoreMemberAllowed($member) {
  foreach (pmssCustomerBackupRelativePaths() as $allowed) {
   if ($member === $allowed || strpos($member, $allowed.'/') === 0) return true;
  }
  return false;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberTypeAllowed')) {
/** Permit only regular files and directories from the archive. */
 function pmssCustomerBackupRestoreMemberTypeAllowed($type) {
  return $type === '0' || $type === '5';
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberNameFromPhar')) {
/** Strip Phar's archive URI prefix to the member path expected by extractTo(). */
 function pmssCustomerBackupRestoreMemberNameFromPhar($archivePath, $entry) {
  $path = $entry instanceof SplFileInfo ? $entry->getPathname() : '';
  $prefix = 'phar://'.$archivePath.'/';
  return is_string($path) && strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
 }
}
