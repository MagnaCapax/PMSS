<?php
// Python related stuff ... Yuck, hope this doesn't break every 2nd minute.

// Install Python pip
$flexget = shell_exec('flexget -V');
// Let's fix python-pip
passthru('easy_install pip; ln -s /usr/local/bin/pip /usr/bin/pip;');
// Install gdrivefs
passthru('pip install --upgrade gdrivefs');

// Install Flexget
if (empty($flexget)) {  // no Flexget
    passthru('pip install --upgrade setuptools functools32 funcsigs; pip install flexget;'); // pip install --upgrade pyopenssl ndg-httpsclient pyasn1 cryptography setuptools');
} elseif (strpos($flexget, 'You are on the latest release.') === false) {   // Outdated, update!
    passthru('pip install --upgrade setuptools funcsigs functools32; pip install --upgrade flexget; pip install --upgrade pyopenssl ndg-httpsclient pyasn1 cryptography');
}



//pip3 install youtube_dl
`pip3 install --upgrade youtube_dl`; 


