#!/usr/bin/php
<?php
copy('/etc/seedbox/config/root.cron', '/etc/cron.d/pmss');
passthru('chmod 644 /etc/cron.d/pmss');


passthru('/etc/init.d/cron force-reload');
passthru('/etc/init.d/cron restart');