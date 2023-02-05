echo "### Installing scripts, configs and skel\n"
rm -rf /etc/skel/*
#rm -rf /scripts/*
#rm -rf /var/www/proxy

cd /tmp/PMSS

cp -r scripts /
cp -r etc /
cp -r var /

chmod o-rwx /scripts
chmod o-rwx /scripts/*
chmod o-rwx /root/*
