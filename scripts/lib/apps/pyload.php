<?php
# Add pyLoad
if (!file_exists('/usr/share/pyload')) {
    echo "## Installing pyLoad cli\n";
    //passthru("wget http://pulsedmedia.com/remote/pkg/pyload-cli-v0.4.9-all.deb -O /tmp/pyload-cli-v0.4.9-all.deb");
    passthru("wget https://github.com/pyload/pyload/releases/download/v0.4.20/pyload-cli_0.4.20_all.deb -O /tmp/pyload.deb");
    // Package installs should be here .... and it's python so should be in docker container regardless since t his is guaranteed to break
    //passthru("apt-get install -y python-pycurl python-crypto python-central");
    passthru("dpkg -i /tmp/pyload.deb");
}

