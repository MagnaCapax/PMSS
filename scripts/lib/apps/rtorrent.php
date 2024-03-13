<?php
// rTorrent installer/updater + libRtorrent + libxmlrpc
// (C) Magna Capax Finland Oy 2024
//
// This can be ran independently too

$rtorrentVersion = shell_exec('rtorrent -h');
// Choose version based on which debian version - 0.9.6 does not compile on deb10, but 0.9.8 has severe issues as well
$debianVersion = file_get_contents('/etc/debian_version');
if ($debianVersion[0] == 1) {
    $rtorrentVersionTarget = '0.9.8-udns';
    $rtorrentVersionTargetLib = '0.13.8-udns';
} else {
    $rtorrentVersionTarget = '0.9.6';
    $rtorrentVersionTargetLib = '0.13.6';
}
$rtorrentCompileOptions = '--with-xmlrpc-c --disable-debug';
$rtorrentCompileOptionsLib = '--with-udns --with-posix-fallocate --disable-debug';
$xmlrpcVersion = '3116';

if (strpos($rtorrentVersion, "version {$rtorrentVersionTarget}.") === false) {  // Yeah i know kinda stupid place to look if we got the latest but ...
    echo "*** Updating rTorrent\n";
    
    shell_exec('rm -rf /usr/local/lib/libtorrent*; ldconfig;'); // Clean up old libtorrent installed files
    
    passthru('apt-get install -y libudns0 libudns-dev');

    echo "**** Remove old rtorrent packages\n";
    //passthru('rm -rf /tmp/rtorrent*; rm -rf /tmp/libtorrent*; rm -rf /tmp/xmlrpc*');
    passthru('rm -rf /tmp/rtorrent*; rm -rf /tmp/libtorrent*');	// Not updating xmlrpc this time
    

    if (!file_exists('/usr/local/lib/libxmlrpc_client.a')) {
        echo "**** Updating xmlrpc-c to rev 3116\n";
        #passthru('cd /tmp; svn checkout http://svn.code.sf.net/p/xmlrpc-c/code/advanced xmlrpc-c -r 2776');
        passthru("cd /tmp; svn checkout http://svn.code.sf.net/p/xmlrpc-c/code/advanced xmlrpc-c -r {$xmlrpcVersion}");
        passthru('cd /tmp/xmlrpc-c; ./configure; make -j12; make install; ldconfig; cd -');
    }

    
    
    echo "**** get new packages\n";
    passthru("cd /tmp; wget http://pulsedmedia.com/remote/pkg/rtorrent-{$rtorrentVersionTarget}.tar.gz; wget http://pulsedmedia.com/remote/pkg/libtorrent-{$rtorrentVersionTargetLib}.tar.gz");
        
    echo "**** uncompressing ...\n";
    passthru("cd /tmp; tar -zxf rtorrent-{$rtorrentVersionTarget}.tar.gz &");
    passthru("cd /tmp; tar -zxf libtorrent-{$rtorrentVersionTargetLib}.tar.gz");
    
    echo "**** compiling ....\n";
    echo "***** libtorrent\n";
    passthru("cd /tmp/libtorrent-{$rtorrentVersionTargetLib}; rm -f scripts/{libtool,lt*}.m4; ./autogen.sh; ./autogen.sh; ./configure {$rtorrentCompileOptionsLib}; make -j12; make install; ldconfig");
    
    echo "***** rtorrent\n";
    passthru("cd /tmp/rtorrent-{$rtorrentVersionTarget}; rm -f scripts/{libtool,lt*}.m4; make clean; ./autogen.sh; ./autogen.sh; ./configure {$rtorrentCompileOptions}; make -j12; make install;");
    
    echo "**** Killing all running rtorrent processes\n";
    # So many because of potentially updating from ancient version, who knows ... Who even knows if you try to update deb 5 machine what happens :P
    passthru('killall -9 rtorrent');
    passthru('killall -9 "rtorrent main"');
    passthru('killall -9 /usr/local/bin/rtorrent');


    if (file_exists('/etc/seedbox/config/template.rtorrentrc')) {
        echo "**** Updating local .rtorrent.rc template\n";
        $localRtorrentRcTemplate = file_get_contents('/etc/seedbox/config/template.rtorrentrc');
        $localRtorrentRcTemplate = str_replace(
            array(
                "umask = 0002\n",
                "hash_interval = 300\n",
                "hash_max_tries = 2\n",
				"use_udp_trackers = yes\n",
            ), '', $localRtorrentRcTemplate);
            
        file_put_contents('/etc/seedbox/config/template.rtorrentrc', $localRtorrentRcTemplate);
    }
    

    echo "*** Update done - rtorrent instances will restart within minute\n";
}
