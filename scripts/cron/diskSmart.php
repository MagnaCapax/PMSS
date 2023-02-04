#!/usr/bin/php
<?php
$logFileDirectory = '/var/run/pmss/smart';
// Get list of blockdevices
$devices = `ls /sys/block/|grep sd|grep -v loop|grep -v md`;
$devices = explode("\n", trim($devices));

$devices[0] ='sda';       // For debug / Testing purposes

if (count($devices) == 0) die("No block devices detected\n");
if (!file_exists($logFileDirectory)) mkdir($logFileDirectory);
if (!is_dir($logFileDirectory)) die("{$logFileDirectory} is normal file and not directory!\n");
if (!is_writeable($logFileDirectory)) die("{$logFileDirectory} is not writeable\n");

// Get smart stats
$deviceSmart = array();
foreach($devices AS $thisDevice) {
    $thisDeviceData = array();
    //$data = `smartctl -a /dev/{$thisDevice}`;
    $data = smartSample();    // For debug / Testing Purposes
    // Following will split the report by "headers", contents 0 => ..start of info sect, 1 => info section to ... smart data sect header => 2 rest
    $dataSegments = explode(' ===', $data);
    $dataInfoSegments = explode("\n", $dataSegments[1]);    // Contains model, serial number etc.
    foreach ($dataInfoSegments AS $thisInfo) {
        $thisInfoSegment = explode(': ', $thisInfo);
        if (count($thisInfoSegment) == 1) continue; // NADA To Parse
        
        if ($thisInfoSegment[0] == 'Device Model')  $thisDeviceData['model'] = trim($thisInfoSegment[1]);
        if ($thisInfoSegment[0] == 'Serial Number')  $thisDeviceData['serial'] = trim($thisInfoSegment[1]);
        if ($thisInfoSegment[0] == 'User Capacity')  {
            $infoPiece = explode(' bytes ', $thisInfoSegment[1]);
            $infoPiece = str_replace(',', '', $infoPiece);
            
            $thisDeviceData['capacity'] = trim($infoPiece[0]);
        }
        
        
        
    }
    
    $dataSmartSection = explode("\n", $dataSegments[2]);
    
    
    $thisDeviceData['rawgz'] = base64_encode( gzcompress($data, 9) );
    var_dump($thisDeviceData);
}








function smartSample() {
    return <<<EOF
smartctl 6.4 2014-10-07 r4002 [x86_64-linux-3.16.0-4-amd64] (local build)
Copyright (C) 2002-14, Bruce Allen, Christian Franke, www.smartmontools.org

=== START OF INFORMATION SECTION ===
Device Model:     TOSHIBA MD04ACA50D
Serial Number:    Z5SIK0FCFFRC
LU WWN Device Id: 5 000039 6bbf81e19
Firmware Version: FS2A
User Capacity:    5,000,981,078,016 bytes [5.00 TB]
Sector Sizes:     512 bytes logical, 4096 bytes physical
Rotation Rate:    7200 rpm
Form Factor:      3.5 inches
Device is:        Not in smartctl database [for details use: -P showall]
ATA Version is:   ATA8-ACS (minor revision not indicated)
SATA Version is:  SATA 3.0, 6.0 Gb/s (current: 3.0 Gb/s)
Local Time is:    Wed Dec  7 09:24:02 2016 EET
SMART support is: Available - device has SMART capability.
SMART support is: Enabled

=== START OF READ SMART DATA SECTION ===
SMART overall-health self-assessment test result: PASSED

General SMART Values:
Offline data collection status:  (0x80) Offline data collection activity
                                        was never started.
                                        Auto Offline Data Collection: Enabled.
Self-test execution status:      (   0) The previous self-test routine completed
                                        without error or no self-test has ever
                                        been run.
Total time to complete Offline
data collection:                (  120) seconds.
Offline data collection
capabilities:                    (0x5b) SMART execute Offline immediate.
                                        Auto Offline data collection on/off support.
                                        Suspend Offline collection upon new
                                        command.
                                        Offline surface scan supported.
                                        Self-test supported.
                                        No Conveyance Self-test supported.
                                        Selective Self-test supported.
SMART capabilities:            (0x0003) Saves SMART data before entering
                                        power-saving mode.
                                        Supports SMART auto save timer.
Error logging capability:        (0x01) Error logging supported.
                                        General Purpose Logging supported.
Short self-test routine
recommended polling time:        (   2) minutes.
Extended self-test routine
recommended polling time:        ( 549) minutes.
SCT capabilities:              (0x003d) SCT Status supported.
                                        SCT Error Recovery Control supported.
                                        SCT Feature Control supported.
                                        SCT Data Table supported.

SMART Attributes Data Structure revision number: 16
Vendor Specific SMART Attributes with Thresholds:
ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
  1 Raw_Read_Error_Rate     0x000b   100   100   050    Pre-fail  Always       -       0
  2 Throughput_Performance  0x0005   100   100   050    Pre-fail  Offline      -       0
  3 Spin_Up_Time            0x0027   100   100   001    Pre-fail  Always       -       10615
  4 Start_Stop_Count        0x0032   100   100   000    Old_age   Always       -       4
  5 Reallocated_Sector_Ct   0x0033   100   100   050    Pre-fail  Always       -       0
  7 Seek_Error_Rate         0x000b   100   100   050    Pre-fail  Always       -       0
  8 Seek_Time_Performance   0x0005   100   100   050    Pre-fail  Offline      -       0
  9 Power_On_Hours          0x0032   100   100   000    Old_age   Always       -       200
 10 Spin_Retry_Count        0x0033   100   100   030    Pre-fail  Always       -       0
 12 Power_Cycle_Count       0x0032   100   100   000    Old_age   Always       -       4
191 G-Sense_Error_Rate      0x0032   100   100   000    Old_age   Always       -       0
192 Power-Off_Retract_Count 0x0032   100   100   000    Old_age   Always       -       2
193 Load_Cycle_Count        0x0032   100   100   000    Old_age   Always       -       4
194 Temperature_Celsius     0x0022   100   100   000    Old_age   Always       -       35 (Min/Max 18/37)
196 Reallocated_Event_Count 0x0032   100   100   000    Old_age   Always       -       0
197 Current_Pending_Sector  0x0032   100   100   000    Old_age   Always       -       0
198 Offline_Uncorrectable   0x0030   100   100   000    Old_age   Offline      -       0
199 UDMA_CRC_Error_Count    0x0032   200   200   000    Old_age   Always       -       0
220 Disk_Shift              0x0002   100   100   000    Old_age   Always       -       0
222 Loaded_Hours            0x0032   100   100   000    Old_age   Always       -       200
223 Load_Retry_Count        0x0032   100   100   000    Old_age   Always       -       0
224 Load_Friction           0x0022   100   100   000    Old_age   Always       -       0
226 Load-in_Time            0x0026   100   100   000    Old_age   Always       -       198
240 Head_Flying_Hours       0x0001   100   100   001    Pre-fail  Offline      -       0

SMART Error Log Version: 1
No Errors Logged

SMART Self-test log structure revision number 1
No self-tests have been logged.  [To run self-tests, use: smartctl -t]

SMART Selective self-test log data structure revision number 1
 SPAN  MIN_LBA  MAX_LBA  CURRENT_TEST_STATUS
    1        0        0  Not_testing
    2        0        0  Not_testing
    3        0        0  Not_testing
    4        0        0  Not_testing
    5        0        0  Not_testing
Selective self-test flags (0x0):
  After scanning selected spans, do NOT read-scan remainder of disk.
If Selective self-test is pending on power-up, resume after 0 minute delay.
EOF;
    
}