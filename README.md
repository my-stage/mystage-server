# myStage Server

## Installing

* Install dependencies
```bash
apt install php-mbstring
```

* Setup mystage-server
```bash
git clone https://github.com/my-stage/mystage-server.git
cd mystage-server/
composer install
```

* Point your virtual host document root to your `mystage-server/public/` directory.
* Ensure `logs/` is web writable.

```bash
chown www-data:www-data -R logs/
```
