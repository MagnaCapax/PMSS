#!/bin/bash
function is_int() { return $(test "$@" -eq "$@" > /dev/null 2>&1); }

if [ -z $1 ]; then
    exit 0;
fi
if [ -z $2 ]; then
    exit 0;
fi

if [ -f "/home/$1/.rtorrent.rc" ]; then
    if $(is_int "$2"); then
        su $1 -c "screen -S rtorrent -X stuff `echo -ne '\030'`"
        su $1 -c 'screen -S rtorrent -X stuff "upload_rate = $2"'
        su $1 -c "screen -S rtorrent -X stuff `echo -ne '\015'`"
        echo "Upload rate set!"
    fi
else
    echo "user not found"
fi