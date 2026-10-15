<?php
/** Bonus helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerBonusDisplayStateRead')) {
/** Read the loyalty percentage from .bonus (the canonical per-user bonus percent, also the
  *  source for BFQ I/O weight), falling back to the aggregate additional GiB allocation. */
 function pmssCustomerBonusDisplayStateRead($bonusPercentPath = '../.bonus', $bonusQuotaPath = '../.bonusQuota') {
  $bonusPercent = pmssCustomerUnsignedIntegerFileRead($bonusPercentPath);
  if ($bonusPercent !== null) {
   return array('unit' => 'percent', 'value' => $bonusPercent, 'state' => $bonusPercent > 0 ? 'applied' : 'zero');
  }

  $bonusQuota = pmssCustomerPositiveIntegerFileRead($bonusQuotaPath);
  if ($bonusQuota !== null) {
   return array('unit' => 'gib', 'value' => $bonusQuota, 'state' => 'applied');
  }

  return array('unit' => 'percent', 'value' => 0, 'state' => 'absent');
 }
}

if (!function_exists('pmssCustomerBonusDisplayTextBuild')) {
/** Keep loyalty percentages distinct from aggregate additional disk space. */
 function pmssCustomerBonusDisplayTextBuild($state) {
  $unit = is_array($state) && isset($state['unit']) && $state['unit'] === 'gib' ? 'gib' : 'percent';
  $value = is_array($state) && is_numeric($state['value'] ?? null)
   ? max(0, (int) $state['value'])
   : 0;

  return $unit === 'gib'
   ? 'ADDITIONAL DISK SPACE: '.number_format($value, 0, '.', '').' GiB'
   : 'BONUS: '.number_format($value).'%';
 }
}

if (!function_exists('pmssCustomerBonusDisplayNoteBuild')) {
/** Explain whether the displayed bonus is present on this server. */
 function pmssCustomerBonusDisplayNoteBuild($state) {
  if (!is_array($state) || !isset($state['state'])) return '';

  switch ((string) $state['state']) {
   case 'applied':
    return isset($state['unit']) && $state['unit'] === 'gib'
     ? 'Additional disk space is included in your quota on this server.'
     : 'Bonus is applied on this server.';
   case 'zero':
    return 'No bonus is applied on this server.';
   case 'absent':
    return 'No bonus is applied on this server yet. Earned bonus is added on top of your base resources, never taken from them.';
   default:
    return '';
  }
 }
}
