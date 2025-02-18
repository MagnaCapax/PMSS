<?php
// Library for PMSS updates
function updateUserFile($file, $user) {
    if (empty($file) or
        empty($user) or
        !file_exists("/home/{$user}") ) {
            echo "Invalid parameters, file: {$file} user: {$user}\n";
    }
    
    $sourceFile = '/etc/skel/' . $file;
    $targetFile = "/home/{$user}/" . $file;
        
    if (!file_exists($sourceFile)) {
        echo "Source file: {$file} is missing\n";
        return;
    }
    
    if (!file_exists($targetFile)) {
        copyToUserSpace($sourceFile, $targetFile, $user);
        echo "Added: {$file} for {$user}\n";
    } else {
        $sourceChecksum = sha1( file_get_contents($sourceFile) );
        $targetChecksum = sha1( file_get_contents($targetFile) );
        if ($sourceChecksum != $targetChecksum) {
            unlink($targetFile);
            copyToUserSpace($sourceFile, $targetFile, $user);
            echo "Updated: {$file} for {$user}\n";
        }
    }

}

function copyToUserSpace($sourceFile, $targetFile, $user) {
    copy($sourceFile, $targetFile);
    //chmod($targetFile, 755);
    passthru("chmod 755 {$targetFile}");
    passthru("chown {$user}.{$user} {$targetFile}");
}

function updateRutorrentConfig($username, $scgiPort) {

    $rutorrentConfig = file_get_contents('/etc/seedbox/config/template.rutorrent.config');
    $accessIni = file_get_contents('/etc/seedbox/config/template.rutorrent.access');
    //$rutorrentConfig = str_replace('$scgi_port = 5000;', '$scgi_port = ' . $scgiPort . ';', $rutorrentConfig);
    $rutorrentConfig = str_replace('$scgi_host = "";', '$scgi_host = "unix:///home/' . $username . '/.rtorrent.socket";', $rutorrentConfig);
    $rutorrentConfig = str_replace('$tempDirectory = null;', "\$tempDirectory = '/home/{$username}/.tmp/';", $rutorrentConfig);
    $rutorrentConfig = str_replace('$topDirectory = \'/\';', "\$topDirectory = '/home/{$username}/';", $rutorrentConfig);
    $rutorrentConfig = str_replace('$log_file = \'/tmp/errors.log\';', "\$log_file = '/home/{$username}/www/rutorrent/errors.log';", $rutorrentConfig);
    file_put_contents("/home/{$username}/www/rutorrent/conf/config.php", $rutorrentConfig);
    file_put_contents("/home/{$username}/www/rutorrent/conf/access.ini", $accessIni);

	// Config filemanager plugin
	// Filemanager plugin had issues - recheck readdition at alter date
/*
	$rutorrentFilemanagerConfig = file_get_contents("/home/{$username}/www/rutorrent/plugins/filemanager/conf.php");
	$rutorrentFilemanagerInit = file_get_contents("/home/{$username}/www/rutorrent/plugins/filemanager/init.js");

	
	$rutorrentFilemanagerConfig = str_replace("fm['tempdir'] = '/tmp'", "fm['tempdir'] = '/home/{$username}/.tmp/'", $rutorrentFilemanagerConfig);

	$rutorrentFilemanagerInit = str_replace("paths: []", "paths: ['/home/{$username}/data']/", $rutorrentFilemanagerInit);
	$rutorrentFilemanagerInit = str_replace("curpath: []", "curpath: ['/home/{$username}/']/", $rutorrentFilemanagerInit);
	$rutorrentFilemanagerInit = str_replace("workpath: []", "workpath: ['/home/{$username}/']/", $rutorrentFilemanagerInit);

	file_put_contents("/home/{$username}/www/rutorrent/plugins/filemanager/conf.php", $rutorrentFilemanagerConfig);
	file_put_contents("/home/{$username}/www/rutorrent/plugins/filemanager/init.js", $rutorrentFilemanagerInit);
*/

}


function get_os_release_data() {
    static $data = null;
    if ($data === null) {
        $data = parse_ini_file('/etc/os-release');
    }
    return $data;
}

function get_distro_name(){
    $data = get_os_release_data();
    return isset($data['ID']) ? $data['ID'] : '';
}

function get_distro_version(){
    $data = get_os_release_data();
    if (isset($data['VERSION_ID'])) {
        if (preg_match('/^([0-9]+)/', $data['VERSION_ID'], $matches)) {
            return $matches[1];
        }
        return $data['VERSION_ID'];
    }
    return '';
}
