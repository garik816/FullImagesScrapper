# FullImagesScrapper for FreshRSS 1.8.2

Loads original/full-size images into FreshRSS entries.

Supported directly:
- F95Zone
- Tapochek.net
- Pornolab.net
- RuTracker.org and common RuTracker mirrors
- Generic RSS/Atom pages when force-all mode is enabled

## RuTracker / Cloudflare

RuTracker can return HTTP 403 with a Cloudflare browser challenge to PHP/cURL clients even while the RSS feed itself remains accessible. Version 1.7.0 can use a Solverr or FlareSolverr-compatible `/v1` service to fetch the topic through a real browser and then parse cover/screenshots from the first `post_body`.

Recommended: Solverr, because its stealth fallback is designed for newer Cloudflare managed/Turnstile challenges.

Example Docker Compose:

```yaml
services:
  solverr:
    image: ghcr.io/unseensnick/solverr:latest
    container_name: solverr
    ports:
      - "127.0.0.1:8191:8191"
    shm_size: 512mb
    restart: unless-stopped
```

Then set in the extension settings:

`Solverr / FlareSolverr API = http://127.0.0.1:8191/v1`

The extension reuses the named browser session `freshrss-rutracker` when the API supports sessions, so after the first Cloudflare solve subsequent topic requests are much faster.

Version 1.7.1 converted current RuTracker Fastpic `postImg` preview URLs
from the confirmed `/thumb/*.jpeg` CDN form to their `/big/*.jpg` originals.
For legacy `fastpic.ru` viewer links it asks Solverr for the signed original URL
and refreshes the cached gallery before that signature expires. This keeps the
cover and screenshot gallery full-size instead of enlarging thumbnails. Version
1.8.0 renames the extension to `FullImagesScrapper`; legacy option keys are
retained so an existing FreshRSS user configuration continues to work.

Version 1.8.1 resolves Pornolab TurboImageHost previews all the way through
their `turboimagehost.com/p/...` viewer page to the original `/sp/<hash>/...`
image. The viewer requires Solverr because it rejects direct cURL requests.

Version 1.8.2 adds NNMClub to the TorrentPier parser and resolves its ImageBan
previews through `imageban.ru/show/...` to `/out/YYYY/MM/DD/...` originals.
Tapochek uses the same TorrentPier extraction path.

## Options

- Maximum images: 0 = unlimited, otherwise 1–100.
- Force processing of other RSS sources.
- Solverr API URL and timeout.
- Cache clear.
- Last-request diagnostics.

## Scheduled feed updates

FullImagesScrapper does not need an open web session to work with FreshRSS.
Run FreshRSS' `app/actualize_script.php` from cron or a systemd timer. The
reference CT uses an active cron service and updates feeds every 20 minutes:

```cron
*/20 * * * * www-data /usr/bin/php /opt/freshrss/app/actualize_script.php > /dev/null 2>&1
```
