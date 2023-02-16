#!/usr/bin/php
<?php
/*  Script to realize some basic network configuration */
$users = trim( shell_exec('/scripts/listUsers.php') );
$users = explode("\n", $users);

$networkConfig = include '/etc/seedbox/config/network';

$localnets = false;
if (file_exists('/etc/seedbox/config/localnet')) {
    $localnets = trim( file_get_contents('/etc/seedbox/config/localnet') );
    $localnets = explode("\n", $localnets);

}



require_once '/scripts/lib/networkInfo.php';
if (!isset($link) or empty($link)) die("Error: Could not get interfaces information\n");


$monitoringRules = shell_exec('/scripts/util/makeMonitoringRules.php');
if (!empty($monitoringRules)) {
    passthru('/sbin/iptables -F OUTPUT'); // let's first clear old rules
    passthru($monitoringRules);
}
passthru('/sbin/iptables -F FORWARD');
passthru('/sbin/iptables -F INPUT');

`echo 1 > /proc/sys/net/ipv4/ip_forward`;
`echo 0 > /proc/sys/net/ipv4/tcp_sack`;		// Disable tcp_sack to mitigate attacks

`iptables -F INPUT`;	// Clear all rules first
`iptables -A INPUT -i {$networkConfig['interface']} -m state --state NEW -p udp --dport 1194 -j ACCEPT`;
`iptables -A INPUT -i tun+ -j ACCEPT`;
`iptables -A FORWARD -i tun+ -o tun+ -j DROP`; // Note: The below rule only prevents client-to-client traffic.
`iptables -A FORWARD -i tun+ -j ACCEPT`;
`iptables -A FORWARD -i tun+ -o {$networkConfig['interface']} -m state --state RELATED,ESTABLISHED -j ACCEPT`;
`iptables -A FORWARD -i {$networkConfig['interface']} -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT`;
`iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -o {$link} -j MASQUERADE`;
`iptables -A OUTPUT -o tun+ -j ACCEPT`;


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
foreach($filterInput AS $thisFilter)
    `iptables -I INPUT -i {$networkConfig['interface']} -s {$thisFilter} -j DROP`;


// Positioned here so it stays higher on the rule list
`iptables -I INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP`;   // Mitigate tcpsack attacks  https://access.redhat.com/security/vulnerabilities/tcpsack
`iptables -I INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j LOG --log-prefix "tcpsack: " --log-level 4`;	// Log tcpsack attacks  TODO Remove this around 03/2020


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

