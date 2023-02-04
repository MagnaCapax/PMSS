<?php
# Add pyLoad
if (!file_exists('/usr/share/pyload')) {
    echo "## Installing pyLoad cli\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/pyload-cli-v0.4.9-all.deb -O /tmp/pyload-cli-v0.4.9-all.deb");
    passthru("apt-get install -y python-pycurl python-crypto python-central");
    passthru("dpkg -i /tmp/pyload-cli-v0.4.9-all.deb");
}

