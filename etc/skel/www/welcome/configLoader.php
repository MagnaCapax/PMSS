<?php

function readSerializedFile($filePath, $default = array())
{
    if (!file_exists($filePath)) {
        return $default;
    }
    $fileContent = file_get_contents($filePath);
    if ($fileContent === false) {
        return $default;
    }
    $unserializedData = @unserialize($fileContent);
    if ($unserializedData !== false) {
        return $unserializedData;
    }
    return $default;
}

function getQuotaInfo()
{
    if (!isset($_GET['quota'])) {
        return array();
    }
    $quotaInfo = urldecode($_GET['quota']);
    $quotaInfo = str_replace('\\', '', $quotaInfo);
    $unserializedData = @unserialize($quotaInfo);
    if ($unserializedData !== false) {
        return $unserializedData;
    }
    return array();
}

$quotaInfo = getQuotaInfo();
$bonusQuota = readSerializedFile('../.bonusQuota', 0);

$vendorDefault = array(
    'name' => 'Pulsed Media',
    'pulsedBox' => true
);

$vendor = readSerializedFile('/etc/seedbox/config/vendor', $vendorDefault);
if (empty($vendor['name'])) {
    $vendor = $vendorDefault;
}
