#!/bin/bash
cd /tmp;
rm -rf PMSS*;
wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tarball_url/ {print $(NF-1)}' | xargs wget -O PMSS.tar.gz;
tar -xzf PMSS.tar.gz;
mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
