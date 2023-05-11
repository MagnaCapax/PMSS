<?php
if (!file_exists('/usr/local/sbin/firehol'))
    passthru('cd /root/compile; wget http://pulsedmedia.com/remote/pkg/firehol-3.1.6.tar.gz; tar -zxvf firehol-3.1.6.tar.gz; cd firehol-3.1.6; ./configure; make -j6; make install;');


