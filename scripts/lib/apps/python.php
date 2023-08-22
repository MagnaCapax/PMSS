<?php
// Python related stuff ... Yuck, hope this doesn't break every 2nd minute.
// Yes it does break randomly. Just as expected. Who comes up with rules like "ensure users are broken!" - thanks very much.


$flexget = shell_exec('flexget -V');

// Let's fix python-pip  -- for the umpteenth time probably. No worries, we can fix it tomorrow too
passthru('easy_install pip; ln -s /usr/local/bin/pip /usr/bin/pip;');

// Install gdrivefs -- is this even used anymore?
echo "### Install gdrivefs\n";
passthru('pip install --upgrade gdrivefs');

// Install Flexget
if (empty($flexget)) {  // no Flexget
    echo "### Install Flexget:\n";
    passthru('pip install --upgrade setuptools functools32 funcsigs chardet==3.0.3 certifi=2017.4.17; pip install flexget;'); // pip install --upgrade pyopenssl ndg-httpsclient pyasn1 cryptography setuptools');
} elseif (strpos($flexget, 'You are on the latest release.') === false) {   // Outdated, update!
    echo "### Update Flexget:\n";
    passthru('pip install --upgrade setuptools funcsigs functools32; pip install --upgrade flexget; pip install --upgrade pyopenssl ndg-httpsclient pyasn1 cryptography');
}



//pip3 install youtube_dl
`pip3 install --upgrade youtube_dl`; 


