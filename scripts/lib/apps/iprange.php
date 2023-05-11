<?php
# Compile iprange
if (!file_exists('/usr/local/bin/iprange'))
    passthru('cd /root/compile; wget http://pulsedmedia.com/remote/pkg/iprange-1.0.4.tar.gz; tar -zxvf iprange-1.0.4.tar.gz; cd iprange-1.0.4; ./configure; make -j6; make install;');

