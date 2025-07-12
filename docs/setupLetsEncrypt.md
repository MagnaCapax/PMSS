# Let's Encrypt Setup

`setupLetsEncrypt.php` obtains an SSL certificate for the server hostname using Certbot.

```
/scripts/util/setupLetsEncrypt.php you@example.com
```

The script expects a valid email address as its only parameter. It will:
- install Certbot (using the distribution package or a Python virtualenv on Debian 10)
- request a certificate for the host returned by `hostname`
- schedule automatic renewal via cron
- regenerate the Nginx configuration

Run this after setting the correct DNS records for the hostname.

**Documentation quality**: The script contains minimal comments. Notes on supported distributions and certificate paths would improve clarity.
