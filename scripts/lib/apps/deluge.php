<?php
if (empty($debianVersion)) $debianVersion = file_get_contents('/etc/debian_version');

echo "#### Deluge install // update\n";


if ($debianVersion[0] == 1) {
  echo "\t*** Deluge pip install:\n";
  passthru('pip install --upgrade twisted[tls] chardet mako pyxdg pillow slimit pygame certifi pyasn1==0.4.6 ');
  passthru('pip install --upgrade pillow'); // For some bizarre pythoness need to run this separately too???  UPD: Still fails occasionally?
  passthru('cd /tmp; rm -rf deluge-2*; wget https://ftp.osuosl.org/pub/deluge/source/2.1/deluge-2.1.0.tar.xz; tar -xvf deluge-2.1.0.tar.xz;');
  passthru('cd /tmp/deluge-2.1.0 python setup.py build; python setup.py install');
} else {
  passthru('apt-get install -y deluged deluge-web');
//      passthru('ln -s /usr/bin/deluged /usr/local/bin/deluged; ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web');
  passthru('systemctl disable deluged');

}



if (file_exists('/usr/bin/deluged') &&
    !file_exists('/usr/local/bin/deluged') ) passthru('ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web; ln -s /usr/bin/deluged /usr/local/bin/deluged; ');


