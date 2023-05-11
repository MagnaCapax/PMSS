<?php

// move following apt install to packages.php circa ~09/23 once we have made sure all nodes have this :)
echo passthru("apt-get install fuse uidmap -y; sysctl -w kernel.unprivileged_userns_clone=1; sysctl -w fs.suid_dumpable=0");

echo passthru("systemctl --user enable docker");

