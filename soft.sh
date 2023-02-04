echo "### Verifying validity -> emptying directories etc."
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

#echo "### Installing proxy"
#cd /var/www
#mkdir proxy
#cd proxy
#wget http://www.sproowl.net/files/sproowl-dimension.tar.gz
#tar -zxf sproowl-dimension.tar.gz
#rm -rf sproowl-dimension.tar.gz
