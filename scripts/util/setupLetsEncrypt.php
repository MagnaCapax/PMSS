#!/usr/bin/php
<?php
if (empty($argv[1])) die("You need to pass e-mail address to this script");
if (strpos($argv[1], '@') == false) die('You need valid e-mail address');
$domain = trim( file_get_contents('/etc/hostname') );
$lsbrelease = trim( shell_exec('/usr/bin/lsb_release -cs') );   #TODO Something more robut than this

//Certbot changed completely how it functions
//`apt-get remove certbot -y`;
//`cd /tmp; wget https://dl.eff.org/certbot-auto; mv certbot-auto /usr/local/bin/certbot-auto; chown root /usr/local/bin/certbot-auto; chmod 0755 /usr/local/bin/certbot-auto`;
//echo `/usr/local/bin/certbot-auto certonly -d {$domain} -n --nginx --agree-tos --email {$argv[1]}`;


//if (!file_exists('/usr/bin/certbot')) echo `apt-get install certbot python-certbot-nginx -y`;

// 3rd time we have to change how certbot is installed *sigh*, they really like breaking old users, don't they? Since now via PIP, pray this works for more than a  week -Aleksi 22/08/2021

#TODO this stuff should be on app installs ...
if (!file_exists('/opt/certbot') && $lsbrelease == 'buster') {
    `python3 -m venv /opt/certbot/`;
    `/opt/certbot/bin/pip install --upgrade pip`;
    `/opt/certbot/bin/pip install certbot certbot-nginx`;
    `ln -s /opt/certbot/bin/certbot /usr/bin/certbot`;
} else echo `apt -y install certbot python3-certbot-nginx; `; #TODO Doesn't belong here for real ...

if (!file_exists('/etc/letsencrypt/live/{$domain}')) echo `/usr/bin/certbot certonly -d {$domain} -n --nginx --agree-tos --email {$argv[1]}`;

if (!file_exists('/etc/cron.d/certbot')) `echo "0 0,12 * * * root python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew" | sudo tee -a /etc/cron.d/certbot > /dev/null`;

/* Why? -Aleksi 22/08/2021
if (!file_exists('/etc/nginx/ssl/selfsigned') &&
    file_exists("/etc/letsencrypt/live/{$domain}") ) {
    mkdir('/etc/nginx/ssl/selfsigned');
    `mv /etc/nginx/ssl/* /etc/nginx/ssl/selfsigned/`;   // Works because you cannot be the dir to itself
    `ln -s /etc/letsencrypt/live/{$domain}/cert.pem /etc/nginx/ssl/nginx.crt`;
    `ln -s /etc/letsencrypt/live/{$domain}/privkey.pem /etc/nginx/ssl/nginx.key`;
}
*/

`/scripts/util/createNginxConfig.php`;
`/etc/init.d/nginx restart`;

