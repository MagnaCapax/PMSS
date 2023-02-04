<?php
if (empty($debianVersion)) $debianVersion = file_get_contents('/etc/debian_version');

if (!file_exists('/usr/local/bin/deluged')) {

    if ($debianVersion[0] == 1) {
      passthru('apt-get install -y python python-twisted python-openssl python-setuptools intltool python-xdg python-chardet geoip-database python-libtorrent python-notify python-pygame python-glade2 librsvg2-common xdg-utils python-mako python-setproctitle python3-setproctitle');
      passthru('pip install --upgrade twisted[tls] chardet mako pyxdg pillow slimit pygame certifi');
      passthru('pip install --upgrade pillow'); // For some bizarre pythoness need to run this separately too???
      passthru('cd /tmp; rm -rf deluge-2*; wget https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.3.tar.xz; tar -xvf deluge-2.0.3.tar.xz;');
      passthru('cd /tmp/deluge-2.0.3; python setup.py build; python setup.py install');
   } else {
      passthru('apt-get install -y deluged deluge-web');
//      passthru('ln -s /usr/bin/deluged /usr/local/bin/deluged; ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web');
      passthru('systemctl disable deluged');

   }

}

if (file_exists('/usr/bin/deluged') &&
    !file_exists('/usr/local/bin/deluged') ) passthru('ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web; ln -s /usr/bin/deluged /usr/local/bin/deluged; ');


