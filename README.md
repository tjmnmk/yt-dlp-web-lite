# yt-dlp-web-lite

A minimal web frontend for playing videos — the video is first downloaded to the server via `yt-dlp` and then streamed to the user. Requires a fast internet connection (upload) on the server side.

## Files

| File | Description |
|---|---|
| `index.php` | Form — paste a YouTube video URL (or a raw video ID), extracts the ID and redirects to `watch.php` |
| `watch.php` | Downloads the video (max 480p) to `videos/` and redirects (302) to the file |
| `config.php` | Configuration: cookies, PO token, low quality time window, deno path, livestreams |
| `style.css` | Styles for `index.php` |
| `.htaccess` | Blocks access to `.part` files and `config.php` |
| `apache2_example_vhost.conf` | Example apache vhost |

## Installation

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php8.5
a2enmod rewrite
a2enmod php
systemctl restart apache2
pip3 install --break-system-packages --upgrade yt-dlp
```

1. Configure the vhost according to the example above
2. Copy `index.php`, `watch.php`, `config.php`, `style.css` and `.htaccess` to `/var/www/html/`
3. `mkdir /var/www/html/videos`
4. `chown www-data: /var/www/html/videos`
5. `mkdir /var/www/.cache` # for the yt-dlp cache
6. `chown www-data /var/www/.cache`
7. `systemctl restart apache2`

## Cron

**/etc/crontab**:
```cron
# delete downloaded videos (disk space)
0 5 * * * www-data find /var/www/html/videos/ -type f -name "*.mp4" -delete
# regular yt-dlp upgrade (YouTube changes often)
*/15 * * * * root yt-dlp --update-to master
```

## Configuration

**`/etc/php/8.5/apache2/php.ini`** — `max_execution_time = 300` (downloads take longer than the default 30 s)

**`config.php`** — if YouTube blocks you, set the cookies file and PO token:
- cookies: https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies
- PO token: https://github.com/yt-dlp/yt-dlp/wiki/PO-Token-Guide#po-token-for-gvs

Other options:
- `LOW_QUALITY_*` — during the night time window (per `LOW_QUALITY_TIME_ZONE`) the lowest quality is downloaded to `videos_low/` (saves bandwidth)
- `DENO_PATH` — path to the deno runtime (required by yt-dlp)
- `DOWNLOAD_LIVESTREAMS` — `true` = allow downloading livestreams (may cause issues with some channels)

