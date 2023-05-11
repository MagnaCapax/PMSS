<?php
// Radarr installer

passthru('cd /tmp; curl -L -O $( curl -s https://api.github.com/repos/Radarr/Radarr/releases | grep linux.tar.gz | grep browser_download_url | head -1 | cut -d \" -f 4 )');
passthru('cd /tmp; rm -rf /opt/Radarr; tar -xvzf Radarr.develop.*.linux.tar.gz; mv Radarr /opt');

