<?php
// Megatools/Megareg install
#Add Megatools if they doesn't exist
if (!file_exists('/usr/local/bin/megareg')) {
    echo "## Installing Megatools\n";
    passthru("cd /tmp; wget http://pulsedmedia.com/remote/pkg/megatools-1.9.94.tar.gz -O megatools.tar.gz; tar -zxvf megatools.tar.gz; cd megatools-1.9.94; ./configure; make -j12; make install; ldconfig");
}

