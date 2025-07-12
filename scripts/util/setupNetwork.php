#!/usr/bin/php
<?php
/*  Script to realize some basic network configuration */
$users = trim( shell_exec('/scripts/listUsers.php') );
$users = explode("\n", $users);

$networkConfig = include '/etc/seedbox/config/network';

$localnets = ['185.148.0.0/22'];
// Define LAN ranges that bypass traffic accounting.
// Administrators may list multiple networks in /etc/seedbox/config/localnet.
if (file_exists('/etc/seedbox/config/localnet')) {
    $cfg = trim(file_get_contents('/etc/seedbox/config/localnet'));
    if ($cfg !== '') {
        $localnets = preg_split('/\r?\n/', $cfg);
    }
} else {
    file_put_contents('/etc/seedbox/config/localnet', "185.148.0.0/22\n");
}



require_once '/scripts/lib/networkInfo.php';
if (!isset($link) or empty($link)) die("Error: Could not get interfaces information\n");

/**
 * Execute an iptables rule and log the command.
 *
 * @param string $rule arguments for iptables binary
 */
function runIptables($rule)
{
    $cmd = "/sbin/iptables $rule";
    echo "Executing: $cmd\n";
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        file_put_contents('/var/log/pmss/iptables.log', date('c') . " ERROR $cmd\n", FILE_APPEND);
    }
}

$monitoringRules = shell_exec('/scripts/util/makeMonitoringRules.php');
if (!empty($monitoringRules)) {
    runIptables('-F OUTPUT');
    foreach (explode("\n", trim($monitoringRules)) as $line) {
        $line = trim(preg_replace('/^\/?sbin\/iptables\s+/', '', $line));
        if ($line !== '') runIptables($line);
    }
}

$replacements = [
    '##IFACE##' => $networkConfig['interface'],
    '##LINK##'  => $link
];

$inputRules = [
    '-F INPUT',
    '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 1194 -j ACCEPT',
    '-A INPUT -i tun+ -j ACCEPT'
];

$forwardRules = [
    '-F FORWARD',
    '-A FORWARD -i tun+ -o tun+ -j DROP',
    '-A FORWARD -i tun+ -j ACCEPT',
    '-A FORWARD -i tun+ -o ##IFACE## -m state --state RELATED,ESTABLISHED -j ACCEPT',
    '-A FORWARD -i ##IFACE## -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT'
];

$natRules = [
    '-t nat -A POSTROUTING -s 10.8.0.0/24 -o ##LINK## -j MASQUERADE'
];

$outputRules = [
    '-A OUTPUT -o tun+ -j ACCEPT'
];

file_put_contents('/proc/sys/net/ipv4/ip_forward', '1');

foreach ($inputRules as $rule) {
    runIptables(str_replace(array_keys($replacements), array_values($replacements), $rule));
}
foreach ($forwardRules as $rule) {
    runIptables(str_replace(array_keys($replacements), array_values($replacements), $rule));
}
foreach ($natRules as $rule) {
    runIptables(str_replace(array_keys($replacements), array_values($replacements), $rule));
}
foreach ($outputRules as $rule) {
    runIptables(str_replace(array_keys($replacements), array_values($replacements), $rule));
}



# Filtering bogons http://bgphelp.com/2017/02/21/ipv4-bogons/
$filterInput = array(
    '0.0.0.0/8',
//    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/3'
);
foreach ($filterInput as $thisFilter) {
    runIptables("-I INPUT -i {$networkConfig['interface']} -s {$thisFilter} -j DROP");
}


// Positioned here so it stays higher on the rule list
runIptables('-I INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP');
runIptables('-I INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j LOG --log-prefix "tcpsack: " --log-level 4');


#TODO We could use a ban list here for ssh brute force attempts etc.


// fireqos rules
$fireqosConfig = file_get_contents('/etc/seedbox/config/template.fireqos');
$fireqosConfig = str_replace('##INTERFACE', $networkConfig['interface'], $fireqosConfig);
$fireqosConfig = str_replace('##SPEED', $networkConfig['speed'], $fireqosConfig);
$fireqosConfigUsers = '';
$fireqosMark = 1;
$fireqosConfigLocal = "class local commit 10%\n";

if ($localnets !== false &&
    count($localnets) > 0) {
    foreach($localnets AS $thisLocalNet)
       $fireqosConfigLocal .= "    match dst {$thisLocalNet}\n";
}
$fireqosConfig = str_replace('##LOCALNETWORK', $fireqosConfigLocal, $fireqosConfig);

if (count($users) > 0) {
  foreach($users AS $thisUser) {
      $thisUid = trim( shell_exec("id -u {$thisUser}") );
      if (empty($thisUid)) continue;  // User does not exist anymore
      $thisLimit = '';

      if (file_exists("/var/run/pmss/trafficLimits/{$thisUser}.enabled"))
          $thisLimit = " ceil {$networkConfig['throttle']['max']}Mbit";

      $fireqosConfigUsers .= "    class {$thisUser} {$thisLimit} \n";  // add rate limiting
      $fireqosConfigUsers .= "      match rawmark {$fireqosMark}\n";
      $fireqosConfigUsers = "       match rawmark {$fireqosMark}\n" . $fireqosConfigUsers; 
      ++$fireqosMark;
  }

//  file_put_contents('/etc/seedbox/config/fireqos.conf', $fireqosConfig);
}
$fireqosConfig = str_replace('##USERMATCHES', $fireqosConfigUsers, $fireqosConfig);
file_put_contents('/etc/seedbox/config/fireqos.conf', $fireqosConfig);
shell_exec ('fireqos start /etc/seedbox/config/fireqos.conf >> /var/log/pmss/fireqos.log 2>&1');


 
/*
$grubCfg = file_get_contents('/boot/grub/grub.cfg');
if (strpos($grubCfg, 'OVH') !== false) exit;    // We won't try to imply fair share rules on OVH kernel
// HTB QOS settings
//$qdisc = shell_exec('tc -s qdisc show');
//if (strpos($qdisc, 'qdisc htb') === false) {
    passthru('iptables -t mangle -F');
    passthru('tc qdisc del dev eth0 root 2>/dev/null');
    
    //passthru('tc qdisc add dev eth0 root handle 1: sfq perturb 15');   // Ensure fair share
    passthru('tc qdisc add dev eth0 root handle 1: htb default 12');   // Ensure fair share
    //passthru('tc class add dev eth0 parent 1: classid 1:1 sfq perturb 15');
   // passthru('tc qdisc add dev eth0 parent 1: handle 10:1 sfq perturb 15');
    
    if (!empty($users)) {
        $users = explode("\n", $users);
        
        $fairShare = ($linkSpeed * 0.95) / count($users);
        if (count($users) >= 5) $fairShare = $fairShare * 2;
            elseif (count($users) != 1) $fairShare = round(  ($linkSpeed * (0.95 - (count($users) / 10))) );    // take 10% off for each users
        
        $fairShare = round($fairShare); // 3 times as much as the real share
        if (count($users) != 1) $fairCeil = round($linkSpeed * (0.95 - (count($users) / 100)));   // Ceil at 95% link speed so that SOMETHING remains for other classes! Minus 1% per user
            else $fairCeil = round($linkSpeed * 0.97);
        
        
        $classNumber = 10;        
        foreach($users AS $thisUser) {
            $prio = $classNumber - 9;
            $command = "tc class add dev eth0 parent 1:1 classid 1:{$classNumber} htb rate {$fairShare}mbps ceil {$fairCeil}mbps burst 4kb cburst 4kb prio {$prio}";
            echo "Exec: {$command}\n";
            passthru($command);
            
            $uid = trim( `id -u {$thisUser}` );
            $command = "iptables -t mangle -A OUTPUT -m owner --uid-owner {$uid} -j CLASSIFY --set-class 1:{$classNumber}";
            passthru($command);
            
            ++$classNumber;
        
        }
    
    }
    
//}

*/

