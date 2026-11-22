# Hanamaru isolated Docker + existing Apache

Use Docker only for Hanamaru. Keep the host Apache (80/443), SSL certs,
existing PHP modules, and other sites untouched.

## Prerequisites / safety

- Run from `/var/www/hanamaru` after `git pull --ff-only`.
- Make sure `.env` is present and NOT tracked in Git.
- Back up `storage/` before any migration; SQLite must remain persistent.
- `docker compose` and a working Docker daemon are required.
- Host port `127.0.0.1:18081` must not be in use.

## Start / verify local

```bash
cd /var/www/hanamaru
mkdir -p storage
chown www-data:www-data storage
chmod 750 storage
docker compose up -d --build
docker compose ps
curl -fsS http://127.0.0.1:18081/?action=config
```

Check JSON contains `settings` and `menus`, then switch only the two
Hanamaru Apache virtualhosts to reverse proxy. Back up both first.

```bash
cp -a /etc/apache2/sites-available/hanamaru.conf /etc/apache2/sites-available/hanamaru.conf.before-docker
cp -a /etc/apache2/sites-available/hanamaru-ssl.conf /etc/apache2/sites-available/hanamaru-ssl.conf.before-docker
a2enmod proxy proxy_http headers
```

In EACH of `hanamaru.conf` and `hanamaru-ssl.conf`, **inside the existing
VirtualHost**, add (do not remove existing SSL certificate directives):

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https" env=HTTPS
ProxyPass / http://127.0.0.1:18081/
ProxyPassReverse / http://127.0.0.1:18081/
```

For the HTTP vhost, use `RequestHeader set X-Forwarded-Proto "http"`
instead. Ensure ACME challenge handling precedes any general ProxyPass
when future Certbot renewals depend on the local webroot.

**IMPORTANT:** Application session cookies rely on `$_SERVER['HTTPS']`,
so Docker needs a correctly trusted reverse-proxy HTTPS indication.
The PHP app must be made proxy-aware or Docker's local Apache must set
the HTTPS CGI variable for forwarded HTTPS **before** accepting real users.
Do not mark the Stripe integration production ready based on a 200 check.

Then:

```bash
apache2ctl configtest && systemctl reload apache2
curl -fsS https://hanamaru.uzero.style/?action=config
```

Do not edit the global PHP module or other sites. Keep the upstream bound
to loopback only.
