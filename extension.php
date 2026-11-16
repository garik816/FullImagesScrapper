<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/UrlRules.php';

final class FullImagesScrapperExtension extends Minz_Extension
{
    private const CACHE_TTL_OK = 21600;      // 6 hours
    private const CACHE_TTL_FAIL = 30;       // 30 seconds; supported forums retry quickly
    private const FETCH_TIMEOUT = 12;
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';
    private const DEFAULT_MAX_IMAGES = 0; // 0 = unlimited
    private const DEFAULT_FORCE_ALL = false;
    private const MAX_ALLOWED_IMAGES = 100;
    private const RUTRACKER_RANGE_END = 1048575; // first 1 MiB contains the first post metadata/images
    private const DEFAULT_SOLVERR_URL = '';
    private const DEFAULT_SOLVERR_TIMEOUT = 90; // seconds
    private const SOLVERR_SESSION = 'freshrss-rutracker';

    private int $maxImages = self::DEFAULT_MAX_IMAGES;
    private bool $forceAll = self::DEFAULT_FORCE_ALL;
    private string $solverrUrl = self::DEFAULT_SOLVERR_URL;
    private int $solverrTimeout = self::DEFAULT_SOLVERR_TIMEOUT;
    /** @var array<string, string|null> */
    private array $fastpicOriginals = [];
    /** @var array<string, string|null> */
    private array $turboImageHostOriginals = [];
    /** @var array<string, string|null> */
    private array $imageBanOriginals = [];

    public function init(): void
    {
        parent::init();

        $this->loadConfiguration();

        // Always process at render time so old database entries are fixed too.
        $this->registerHook('entry_before_display', [$this, 'processEntry']);

        // Force mode also processes new/updated RSS entries during feed refresh,
        // so the enriched content is stored instead of being generated only on display.
        if ($this->forceAll) {
            $this->registerHook('entry_before_insert', [$this, 'processEntry']);
            $this->registerHook('entry_before_update', [$this, 'processEntry']);
        }

        Minz_View::appendStyle($this->getFileUrl('style.css'));
    }

    public function handleConfigureAction(): void
    {
        if (!FreshRSS_Context::hasUserConf()) {
            return;
        }

        if (Minz_Request::isPost()) {
            $maxImages = Minz_Request::paramInt('f95_full_images_max_images');
            $maxImages = max(0, min(self::MAX_ALLOWED_IMAGES, $maxImages));
            $forceAll = Minz_Request::paramBoolean('f95_full_images_force_all');
            $solverrUrl = trim(Minz_Request::paramString('f95_full_images_solverr_url', plaintext: true));
            $solverrUrl = $this->normalizeSolverrUrl($solverrUrl);
            $solverrTimeout = max(20, min(180, Minz_Request::paramInt('f95_full_images_solverr_timeout')));

            FreshRSS_Context::userConf()->_attribute('f95_full_images_max_images', $maxImages);
            FreshRSS_Context::userConf()->_attribute('f95_full_images_force_all', $forceAll);
            FreshRSS_Context::userConf()->_attribute('f95_full_images_solverr_url', $solverrUrl);
            FreshRSS_Context::userConf()->_attribute('f95_full_images_solverr_timeout', $solverrTimeout);
            FreshRSS_Context::userConf()->save();

            $this->maxImages = $maxImages;
            $this->forceAll = $forceAll;
            $this->solverrUrl = $solverrUrl;
            $this->solverrTimeout = $solverrTimeout;

            if (Minz_Request::paramBoolean('f95_full_images_clear_cache')) {
                $this->clearCache();
            }
        }
    }

    public function getMaxImages(): int
    {
        return $this->maxImages;
    }

    public function getForceAll(): bool
    {
        return $this->forceAll;
    }

    public function getSolverrUrl(): string
    {
        return $this->solverrUrl;
    }

    public function getSolverrTimeout(): int
    {
        return $this->solverrTimeout;
    }

    private function loadConfiguration(): void
    {
        if (!FreshRSS_Context::hasUserConf()) {
            return;
        }

        $save = false;

        $maxImages = FreshRSS_Context::userConf()->attributeInt('f95_full_images_max_images');
        if ($maxImages === null) {
            $maxImages = self::DEFAULT_MAX_IMAGES;
            FreshRSS_Context::userConf()->_attribute('f95_full_images_max_images', $maxImages);
            $save = true;
        }
        $this->maxImages = max(0, min(self::MAX_ALLOWED_IMAGES, $maxImages));

        $forceAll = FreshRSS_Context::userConf()->attributeBool('f95_full_images_force_all');
        if ($forceAll === null) {
            $forceAll = self::DEFAULT_FORCE_ALL;
            FreshRSS_Context::userConf()->_attribute('f95_full_images_force_all', $forceAll);
            $save = true;
        }
        $this->forceAll = $forceAll;

        $solverrUrl = FreshRSS_Context::userConf()->attributeString('f95_full_images_solverr_url');
        if ($solverrUrl === null) {
            $solverrUrl = self::DEFAULT_SOLVERR_URL;
            FreshRSS_Context::userConf()->_attribute('f95_full_images_solverr_url', $solverrUrl);
            $save = true;
        }
        $this->solverrUrl = $this->normalizeSolverrUrl($solverrUrl);

        $solverrTimeout = FreshRSS_Context::userConf()->attributeInt('f95_full_images_solverr_timeout');
        if ($solverrTimeout === null) {
            $solverrTimeout = self::DEFAULT_SOLVERR_TIMEOUT;
            FreshRSS_Context::userConf()->_attribute('f95_full_images_solverr_timeout', $solverrTimeout);
            $save = true;
        }
        $this->solverrTimeout = max(20, min(180, $solverrTimeout));

        if ($save) {
            FreshRSS_Context::userConf()->save();
        }
    }

    public function processEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        $link = html_entity_decode($entry->link(true), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $original = $entry->content(false);

        // First fix the common RSS pattern where <img> is a thumbnail but its
        // surrounding <a href> points directly to the original image.
        $content = $this->upgradeLinkedImageThumbnails($original, $link);
        $content = $this->upgradeExistingThumbnails($content);
        $content = $this->upgradeTorrentPierPostImgPlaceholders($content, $link);

        $isF95Thread = $this->isF95ThreadUrl($link);

        if ($isF95Thread) {
            $hasAttachPlaceholder = stripos($content, '[ATTACH') !== false;

            if ($hasAttachPlaceholder || $this->forceAll) {
                $remoteImages = $this->limitRemoteImages($this->getThreadImages($link));

                if ($remoteImages !== []) {
                    if ($hasAttachPlaceholder) {
                        $content = $this->replaceAttachPlaceholders($content, $remoteImages);
                    }

                    if ($this->forceAll) {
                        $content = $this->appendMissingImages($content, $remoteImages);
                    }
                }
            }
        } elseif ($this->isTorrentPierThreadUrl($link)) {
            // Dedicated supported sites are always processed.  The force-all
            // switch is only required for unknown/generic RSS sources.
            $remoteImages = $this->limitRemoteImages($this->getPageImages($link, $entry));
            if ($remoteImages !== []) {
                $content = $this->appendMissingGenericImages($content, $remoteImages, $link);
            }
        } elseif ($this->forceAll && $this->isFetchableHttpUrl($link)) {
            $remoteImages = $this->limitRemoteImages($this->getPageImages($link, $entry));
            if ($remoteImages !== []) {
                $content = $this->appendMissingGenericImages($content, $remoteImages, $link);
            }
        }

        if ($content !== $original) {
            $entry->_content($content);
        }

        return $entry;
    }

    /** @param list<string> $images
     *  @return list<string>
     */
    private function limitRemoteImages(array $images): array
    {
        if ($this->maxImages <= 0) {
            return $images;
        }

        return array_slice($images, 0, $this->maxImages);
    }

    private function isF95ThreadUrl(string $url): bool
    {
        return FullImagesScrapperUrlRules::isF95ThreadUrl($url);
    }


    private function isTorrentPierThreadUrl(string $url): bool
    {
        return FullImagesScrapperUrlRules::isTorrentPierThreadUrl($url);
    }

    private function isRutrackerThreadUrl(string $url): bool
    {
        return FullImagesScrapperUrlRules::isRutrackerThreadUrl($url);
    }

    private function isFetchableHttpUrl(string $url): bool
    {
        return FullImagesScrapperUrlRules::isFetchableHttpUrl($url);
    }

    /**
     * Generic RSS thumbnail upgrade: when an image is wrapped in a link that points
     * directly to an image, use that linked image as <img src>. This is lossless and
     * works for many forum/image-host RSS feeds, including Tapochek previews.
     */
    private function upgradeLinkedImageThumbnails(string $html, string $baseUrl): string
    {
        if ($html === '' || stripos($html, '<img') === false || stripos($html, '<a') === false) {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="rss-full-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="rss-full-root"]//a[@href][.//img]');
        if (!($nodes instanceof DOMNodeList)) {
            return $html;
        }

        foreach ($nodes as $anchor) {
            if (!($anchor instanceof DOMElement)) {
                continue;
            }

            $full = $this->normalizeGenericImageUrl($anchor->getAttribute('href'), $baseUrl);
            if ($full === null || !$this->looksLikeDirectImageUrl($full)) {
                continue;
            }

            $imgs = $xpath->query('.//img', $anchor);
            if (!($imgs instanceof DOMNodeList)) {
                continue;
            }

            foreach ($imgs as $img) {
                if (!($img instanceof DOMElement)) {
                    continue;
                }
                $img->setAttribute('src', $full);
                foreach (['srcset', 'data-src', 'data-url', 'data-original', 'width', 'height'] as $attr) {
                    $img->removeAttribute($attr);
                }
                $this->appendClass($img, 'f95-full-image');
            }
        }

        $root = $xpath->query('//*[@id="rss-full-root"]')->item(0);
        return $root instanceof DOMElement ? $this->innerHtml($root) : $html;
    }

    /**
     * Convert TorrentPier lazy-image placeholders already present in an RSS item:
     * <var class="postImg" title="FULL_URL"> -> <img src="FULL_URL">.
     * This avoids a page fetch when the feed already contains the original URL.
     */
    private function upgradeTorrentPierPostImgPlaceholders(string $html, string $baseUrl): string
    {
        if ($html === '' || stripos($html, 'postImg') === false || !class_exists('DOMDocument')) {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="torrentpier-rss-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="torrentpier-rss-root"]//var[contains(concat(" ", normalize-space(@class), " "), " postImg ")]');
        if (!($nodes instanceof DOMNodeList) || $nodes->length === 0) {
            return $html;
        }

        // Snapshot because DOMNodeList is live while nodes are replaced.
        $placeholders = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $placeholders[] = $node;
            }
        }

        foreach ($placeholders as $var) {
            $url = null;
            foreach (['title', 'data-src', 'data-url'] as $attribute) {
                $candidate = $this->normalizeGenericImageUrl($var->getAttribute($attribute), $baseUrl);
                if ($candidate !== null && !$this->looksLikeUiImage($candidate)) {
                    $url = $candidate;
                    break;
                }
            }
            if ($url === null) {
                continue;
            }

            $img = $dom->createElement('img');
            $img->setAttribute('src', $url);
            $img->setAttribute('alt', '');
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('class', 'f95-full-image');
            $var->parentNode?->replaceChild($img, $var);
        }

        $root = $xpath->query('//*[@id="torrentpier-rss-root"]')->item(0);
        return $root instanceof DOMElement ? $this->innerHtml($root) : $html;
    }

    /**
     * Change <img src=".../thumb/file"> into the full URL stored in the surrounding <a href=".../file">.
     * This does not resize or recompress the image.
     */
    private function upgradeExistingThumbnails(string $html): string
    {
        if (stripos($html, 'attachments.f95zone.to/') === false || stripos($html, '/thumb/') === false) {
            return $html;
        }

        if (!class_exists('DOMDocument')) {
            return $this->upgradeExistingThumbnailsRegex($html);
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="f95-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="f95-root"]//img[contains(@src, "/thumb/")]');
        if (!($nodes instanceof DOMNodeList)) {
            return $html;
        }

        foreach ($nodes as $img) {
            if (!($img instanceof DOMElement)) {
                continue;
            }

            $src = $this->normalizeAttachmentUrl($img->getAttribute('src'));
            if ($src === null) {
                continue;
            }

            $anchor = $img->parentNode;
            while ($anchor instanceof DOMElement && strtolower($anchor->tagName) !== 'a') {
                $anchor = $anchor->parentNode;
            }

            $full = null;
            if ($anchor instanceof DOMElement) {
                $full = $this->normalizeAttachmentUrl($anchor->getAttribute('href'));
            }

            // Prefer href: F95Zone explicitly puts the original attachment there.
            $full ??= $src;
            $img->setAttribute('src', $full);
            $img->removeAttribute('srcset');
            $img->removeAttribute('data-src');
            $img->removeAttribute('data-url');
            $img->removeAttribute('width');
            $img->removeAttribute('height');
            $this->appendClass($img, 'f95-full-image');
        }

        $root = $xpath->query('//*[@id="f95-root"]')->item(0);
        if (!($root instanceof DOMElement)) {
            return $html;
        }

        return $this->innerHtml($root);
    }

    /** @return list<string> */
    private function getPageImages(string $pageUrl, ?FreshRSS_Entry $entry = null): array
    {
        $cacheFile = $this->cacheFile('page:v1.8.2:' . $pageUrl);
        $stale = $this->readCache($cacheFile, false);
        $fresh = $this->readCache($cacheFile, true);
        if ($fresh !== null && $fresh !== []) {
            return $fresh;
        }

        $attempts = [];

        // Current RuTracker topic pages are large Windows-1251 documents and the
        // actual release images are lazy placeholders (<var class="postImg">).
        // Fetch only the beginning of the topic first. This is faster, avoids
        // occasional full-page timeouts, and mirrors the strategy used by current
        // RuTracker cover tools. One MiB is intentionally generous so screenshots
        // below the cover are normally included too.
        if ($this->isRutrackerThreadUrl($pageUrl)) {
            $range = $this->fetchRutrackerRange($pageUrl, $entry);
            $attempts[] = $range;
            if ($range['body'] !== '') {
                $images = $this->extractTorrentPierFirstPostImages($range['body'], $pageUrl);
                if ($images !== []) {
                    $this->writeCache($cacheFile, $images, true);
                    $this->writeDiagnostic($pageUrl, $attempts, count($images), 'rutracker-range');
                    return $images;
                }
            }

            // A 403 on current RuTracker is typically a Cloudflare browser challenge.
            // Plain PHP/cURL cannot reliably pass modern managed/Turnstile challenges,
            // so use a configured Solverr/FlareSolverr-compatible browser service.
            if ($this->solverrUrl !== '' && ($range['status'] === 403 || $this->looksLikeCloudflareChallenge($range['body']))) {
                $solved = $this->fetchPageViaSolverr($pageUrl);
                $attempts[] = $solved;
                if ($solved['body'] !== '') {
                    $images = $this->extractTorrentPierFirstPostImages($solved['body'], $pageUrl);
                    if ($images !== []) {
                        $this->writeCache($cacheFile, $images, true);
                        $this->writeDiagnostic($pageUrl, $attempts, count($images), 'solverr');
                        return $images;
                    }
                }
            }
        }

        // Then use FreshRSS' HTTP layer. This inherits the feed's configured
        // cookies/proxy/authentication, which is important for private trackers.
        $result = $this->fetchPageViaFreshRss($pageUrl, $entry);
        $attempts[] = $result;
        $body = $result['body'];

        if ($body !== '') {
            $images = $this->isTorrentPierThreadUrl($pageUrl)
                ? $this->extractTorrentPierFirstPostImages($body, $pageUrl)
                : $this->extractGenericPageImages($body, $pageUrl);

            if ($images !== []) {
                $this->writeCache($cacheFile, $images, true);
                $this->writeDiagnostic($pageUrl, $attempts, count($images), 'freshRSS');
                return $images;
            }
        }

        // RuTracker / Pornolab / Tapochek sometimes return a reduced or anti-bot
        // page to FreshRSS' fetcher. Retry known hosts with a browser-like direct
        // cURL request. This is deliberately restricted to the built-in host list.
        if ($this->isTorrentPierThreadUrl($pageUrl)) {
            $direct = $this->fetchPageViaDirectCurl($pageUrl, $entry);
            $attempts[] = $direct;
            if ($direct['body'] !== '') {
                $images = $this->extractTorrentPierFirstPostImages($direct['body'], $pageUrl);
                if ($images !== []) {
                    $this->writeCache($cacheFile, $images, true);
                    $this->writeDiagnostic($pageUrl, $attempts, count($images), 'direct-curl');
                    return $images;
                }
            }
        }

        // Do not keep a long-lived negative cache for supported forums. A first
        // request may hit rate limiting / a transient login page and should recover
        // quickly on the next display.
        @unlink($cacheFile);
        $this->writeDiagnostic($pageUrl, $attempts, 0, 'failed');
        Minz_Log::warning('FullImagesScrapper: no usable images found in RSS article ' . $pageUrl);
        return $stale ?? [];
    }

    private function normalizeSolverrUrl(string $url): string
    {
        return FullImagesScrapperUrlRules::normalizeSolverrUrl($url);
    }

    private function preferOriginalImageUrl(string $url): string
    {
        return FullImagesScrapperUrlRules::preferOriginalImageUrl($url);
    }

    private function resolveLegacyFastpicViewerImage(string $viewerUrl): ?string
    {
        $parts = parse_url($viewerUrl);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($host, ['fastpic.ru', 'www.fastpic.ru'], true)
            || !preg_match('~^/view/[^?]+\.html$~i', $path)) {
            return null;
        }

        $filename = preg_replace('~\.html$~i', '', basename($path));
        if (!is_string($filename) || $filename === '') {
            return null;
        }

        // Legacy Fastpic originals now require a short-lived signed CDN URL.
        // The viewer page exposes that URL only after its browser checks run.
        $page = $this->fetchPageViaSolverr($viewerUrl);
        if ($page['body'] === '' || $page['status'] < 200 || $page['status'] >= 300) {
            return null;
        }

        if (!preg_match_all(
            '~https?://i\d+\.fastpic\.(?:org|ru)/big/[^"\'<>\s]+~i',
            $page['body'],
            $matches,
        )) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidatePath = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
            if (basename($candidatePath) === $filename) {
                return $this->normalizeGenericImageUrl($candidate, $viewerUrl);
            }
        }

        return null;
    }

    private function resolveFastpicThumbnailOriginal(string $url): string
    {
        if (array_key_exists($url, $this->fastpicOriginals)) {
            return $this->fastpicOriginals[$url] ?? $url;
        }

        if (!preg_match(
            '~^https?://i(\d+)\.fastpic\.ru/thumb/(\d{4}/\d{4})/[a-f0-9]{2}/([^/?#]+)$~i',
            $url,
            $matches,
        )) {
            return $url;
        }

        $viewerUrl = 'https://fastpic.ru/view/' . $matches[1] . '/' . $matches[2] . '/' . $matches[3] . '.html';
        $this->fastpicOriginals[$url] = $this->resolveLegacyFastpicViewerImage($viewerUrl);
        return $this->fastpicOriginals[$url] ?? $url;
    }

    private function resolveTurboImageHostViewerImage(string $viewerUrl): ?string
    {
        $parts = parse_url($viewerUrl);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($host, ['turboimagehost.com', 'www.turboimagehost.com'], true)
            || !preg_match('~^/p/\d+/([^/?#]+)\.html$~i', $path, $matches)) {
            return null;
        }

        $filename = rawurldecode($matches[1]);
        // TurboImageHost blocks plain cURL on viewer pages. The browser-rendered
        // page contains the exact /sp/<hash>/ original, while /t/<id>_... is only
        // the thumbnail shown in TorrentPier posts.
        $page = $this->fetchPageViaSolverr($viewerUrl);
        if ($page['body'] === '' || $page['status'] < 200 || $page['status'] >= 300) {
            return null;
        }

        if (!preg_match_all(
            '~https?://[a-z0-9.-]+\.turboimg\.net/sp/[a-f0-9]+/[^"\'< >\s]+~i',
            $page['body'],
            $matches,
        )) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidatePath = (string)(parse_url($candidate, PHP_URL_PATH) ?? '');
            if (rawurldecode(basename($candidatePath)) === $filename) {
                return $this->normalizeGenericImageUrl($candidate, $viewerUrl);
            }
        }

        return null;
    }

    private function resolveTurboImageHostThumbnailOriginal(string $url): string
    {
        if (array_key_exists($url, $this->turboImageHostOriginals)) {
            return $this->turboImageHostOriginals[$url] ?? $url;
        }

        if (!preg_match(
            '~^https?://[a-z0-9.-]+\.turboimg\.net/t/(\d+)_([^/?#]+)$~i',
            $url,
            $matches,
        )) {
            return $url;
        }

        $viewerUrl = 'https://www.turboimagehost.com/p/' . $matches[1] . '/'
            . rawurlencode(rawurldecode($matches[2])) . '.html';
        $this->turboImageHostOriginals[$url] = $this->resolveTurboImageHostViewerImage($viewerUrl);
        return $this->turboImageHostOriginals[$url] ?? $url;
    }

    private function resolveImageBanViewerImage(string $viewerUrl, ?string $fallbackUrl = null): ?string
    {
        if (array_key_exists($viewerUrl, $this->imageBanOriginals)) {
            return $this->imageBanOriginals[$viewerUrl] ?? $fallbackUrl;
        }

        $parts = parse_url($viewerUrl);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($host, ['imageban.ru', 'www.imageban.ru'], true)
            || !preg_match('~^/show/(\d{4})/(\d{2})/(\d{2})/([a-f0-9]+)/([a-z0-9]+)$~i', $path, $matches)) {
            return null;
        }

        // ImageBan permits a normal request to its viewer. Its page exposes the
        // canonical full-size /out/ URL; NNMClub itself only embeds /thumbs/.
        $page = $this->fetchPageViaFreshRss($viewerUrl, null);
        if ($page['body'] === '' || $page['status'] < 200 || $page['status'] >= 300) {
            $page = $this->fetchPageViaSolverr($viewerUrl);
        }

        $original = null;
        if ($page['body'] !== '' && preg_match_all(
            '~https?://i\d+\.imageban\.ru/out/' . $matches[1] . '/' . $matches[2] . '/' . $matches[3]
                . '/' . $matches[4] . '\.(?:jpe?g|png|webp|gif|avif)~i',
            $page['body'],
            $candidates,
        )) {
            $original = $this->normalizeGenericImageUrl(
                html_entity_decode($candidates[0][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $viewerUrl,
            );
        }

        $this->imageBanOriginals[$viewerUrl] = $original ?? $fallbackUrl;
        return $this->imageBanOriginals[$viewerUrl];
    }

    private function resolveImageBanThumbnailOriginal(string $url): string
    {
        if (array_key_exists($url, $this->imageBanOriginals)) {
            return $this->imageBanOriginals[$url] ?? $url;
        }

        $thumbnailUrl = $url;
        $parts = parse_url($thumbnailUrl);
        if (is_array($parts) && strtolower((string)($parts['host'] ?? '')) === 'nnmstatic.win') {
            parse_str((string)($parts['query'] ?? ''), $query);
            if (is_string($query['link'] ?? null)) {
                $thumbnailUrl = html_entity_decode($query['link'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (!preg_match(
            '~^https?://(i\d+\.imageban\.ru)/thumbs/(\d{4})\.(\d{2})\.(\d{2})/([a-f0-9]+)\.(jpe?g|png|webp|gif|avif)$~i',
            $thumbnailUrl,
            $matches,
        )) {
            return $url;
        }

        $viewerUrl = 'https://imageban.ru/show/' . $matches[2] . '/' . $matches[3] . '/'
            . $matches[4] . '/' . $matches[5] . '/' . strtolower($matches[6]);
        $fallbackUrl = 'https://' . $matches[1] . '/out/' . $matches[2] . '/' . $matches[3] . '/'
            . $matches[4] . '/' . $matches[5] . '.' . $matches[6];
        $original = $this->resolveImageBanViewerImage($viewerUrl, $fallbackUrl);
        $this->imageBanOriginals[$url] = $original;
        return $original ?? $url;
    }

    private function resolveRemoteImageUrl(string $url): string
    {
        return $this->resolveImageBanThumbnailOriginal(
            $this->resolveTurboImageHostThumbnailOriginal(
                $this->resolveFastpicThumbnailOriginal($url),
            ),
        );
    }

    private function resolveImageViewerUrl(string $url): ?string
    {
        return $this->resolveLegacyFastpicViewerImage($url)
            ?? $this->resolveTurboImageHostViewerImage($url)
            ?? $this->resolveImageBanViewerImage($url);
    }

    private function looksLikeCloudflareChallenge(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        foreach (['Just a moment', 'cf-chl-', 'challenge-platform', 'Cloudflare Ray ID', 'Verify you are human'] as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Use Solverr / FlareSolverr-compatible /v1 API to obtain browser-rendered HTML.
     * A persistent named session is reused when supported so cf_clearance remains warm.
     *
     * @return array{body:string,status:int,error:string,effective_url:string,method:string}
     */
    private function fetchPageViaSolverr(string $pageUrl): array
    {
        if ($this->solverrUrl === '' || !function_exists('curl_init')) {
            return ['body' => '', 'status' => 0, 'error' => 'Solverr not configured', 'effective_url' => $pageUrl, 'method' => 'solverr'];
        }

        $payload = [
            'cmd' => 'request.get',
            'url' => $pageUrl,
            'session' => self::SOLVERR_SESSION,
            'maxTimeout' => $this->solverrTimeout * 1000,
            'waitInSeconds' => 1,
            'disableMedia' => true,
        ];

        $api = $this->solverrPost($payload);
        if (!$api['ok'] && $this->solverrErrorLooksLikeMissingSession($api['message'])) {
            $created = $this->solverrPost([
                'cmd' => 'sessions.create',
                'session' => self::SOLVERR_SESSION,
            ]);
            if ($created['ok'] || stripos($created['message'], 'exist') !== false) {
                $api = $this->solverrPost($payload);
            }
        }

        if (!$api['ok']) {
            return [
                'body' => '',
                'status' => $api['http_status'],
                'error' => $api['message'] !== '' ? $api['message'] : $api['error'],
                'effective_url' => $pageUrl,
                'method' => 'solverr',
            ];
        }

        $data = $api['data'];
        $solution = is_array($data['solution'] ?? null) ? $data['solution'] : [];
        $body = is_string($solution['response'] ?? null) ? $solution['response'] : '';
        $status = is_int($solution['status'] ?? null) ? $solution['status'] : $api['http_status'];
        $effective = is_string($solution['url'] ?? null) && $solution['url'] !== '' ? $solution['url'] : $pageUrl;
        $body = $this->normalizeFetchedHtmlEncoding($body);

        if ($this->looksLikeCloudflareChallenge($body)) {
            return [
                'body' => $body,
                'status' => $status,
                'error' => 'Solverr returned an unsolved Cloudflare challenge',
                'effective_url' => $effective,
                'method' => 'solverr',
            ];
        }

        return [
            'body' => $body,
            'status' => $status,
            'error' => '',
            'effective_url' => $effective,
            'method' => 'solverr',
        ];
    }

    private function solverrErrorLooksLikeMissingSession(string $message): bool
    {
        $m = strtolower($message);
        return str_contains($m, 'session') && (
            str_contains($m, 'not found') ||
            str_contains($m, 'does not exist') ||
            str_contains($m, 'invalid') ||
            str_contains($m, 'unknown')
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,http_status:int,error:string,message:string,data:array<string,mixed>}
     */
    private function solverrPost(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return ['ok' => false, 'http_status' => 0, 'error' => 'JSON encode failed', 'message' => '', 'data' => []];
        }

        $ch = curl_init();
        if ($ch === false) {
            return ['ok' => false, 'http_status' => 0, 'error' => 'curl_init failed', 'message' => '', 'data' => []];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->solverrUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->solverrTimeout + 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'http_status' => $httpStatus, 'error' => $error !== '' ? $error : 'Empty Solverr response', 'message' => '', 'data' => []];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'http_status' => $httpStatus, 'error' => 'Invalid Solverr JSON response', 'message' => '', 'data' => []];
        }
        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        $ok = strtolower((string)($data['status'] ?? '')) === 'ok';

        return [
            'ok' => $ok,
            'http_status' => $httpStatus,
            'error' => $error,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array{body:string,status:int,error:string,effective_url:string,method:string}
     */
    private function fetchPageViaFreshRss(string $pageUrl, ?FreshRSS_Entry $entry): array
    {
        $attributes = ['timeout' => self::FETCH_TIMEOUT, 'curl_params' => []];
        $curlOptions = [];

        $feed = $entry?->feed();
        if ($feed instanceof FreshRSS_Feed) {
            $attributes = $feed->attributes();
            $attributes['timeout'] = self::FETCH_TIMEOUT;
            $curlOptions = $feed->curlOptions();
        }

        $params = is_array($attributes['curl_params'] ?? null) ? $attributes['curl_params'] : [];
        $params = FreshRSS_http_Util::sanitizeCurlParams($params);
        $params[CURLOPT_USERAGENT] = self::USER_AGENT;

        $headers = is_array($params[CURLOPT_HTTPHEADER] ?? null) ? $params[CURLOPT_HTTPHEADER] : [];
        $headers = $this->mergeRequestHeaders($headers, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ]);
        $params[CURLOPT_HTTPHEADER] = $headers;
        $attributes['curl_params'] = $params;

        $result = FreshRSS_http_Util::httpGet($pageUrl, null, 'html', $attributes, $curlOptions);

        return [
            'body' => is_string($result['body'] ?? null) ? $result['body'] : '',
            'status' => is_int($result['status'] ?? null) ? $result['status'] : 0,
            'error' => is_string($result['error'] ?? null) ? $result['error'] : '',
            'effective_url' => is_string($result['effective_url'] ?? null) ? $result['effective_url'] : $pageUrl,
            'method' => 'freshRSS',
        ];
    }

    /**
     * Fetch the first part of a RuTracker topic with an HTTP Range request.
     * RuTracker keeps cover/screenshot URLs in the first post as postImg VAR tags.
     * Feed cookies/proxy/auth options are reused when the user configured them.
     *
     * @return array{body:string,status:int,error:string,effective_url:string,method:string}
     */
    private function fetchRutrackerRange(string $pageUrl, ?FreshRSS_Entry $entry): array
    {
        if (!function_exists('curl_init') || !$this->isRutrackerThreadUrl($pageUrl)) {
            return ['body' => '', 'status' => 0, 'error' => 'cURL unavailable', 'effective_url' => $pageUrl, 'method' => 'rutracker-range'];
        }

        $ch = curl_init();
        if ($ch === false) {
            return ['body' => '', 'status' => 0, 'error' => 'curl_init failed', 'effective_url' => $pageUrl, 'method' => 'rutracker-range'];
        }

        $options = [
            CURLOPT_URL => $pageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ACCEPT_ENCODING => 'identity',
            CURLOPT_REFERER => $this->topicReferer($pageUrl),
            CURLOPT_RANGE => '0-' . self::RUTRACKER_RANGE_END,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Range: bytes=0-' . self::RUTRACKER_RANGE_END,
                'Connection: close',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        $this->applyEntryNetworkOptions($options, $entry);

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        $body = is_string($body) ? $body : '';
        $body = $this->decodeWindows1251($body);

        return [
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'effective_url' => $effective !== '' ? $effective : $pageUrl,
            'method' => 'rutracker-range',
        ];
    }

    /**
     * Browser-like fallback for the explicitly supported TorrentPier hosts only.
     * Reuses feed cookie/proxy/auth options when available.
     *
     * @return array{body:string,status:int,error:string,effective_url:string,method:string}
     */
    private function fetchPageViaDirectCurl(string $pageUrl, ?FreshRSS_Entry $entry): array
    {
        if (!function_exists('curl_init') || !$this->isTorrentPierThreadUrl($pageUrl)) {
            return ['body' => '', 'status' => 0, 'error' => 'cURL unavailable', 'effective_url' => $pageUrl, 'method' => 'direct-curl'];
        }

        $ch = curl_init();
        if ($ch === false) {
            return ['body' => '', 'status' => 0, 'error' => 'curl_init failed', 'effective_url' => $pageUrl, 'method' => 'direct-curl'];
        }

        $options = [
            CURLOPT_URL => $pageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_REFERER => $this->topicReferer($pageUrl),
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        // RuTracker's IPv6 path can behave differently on some VPS/LXC networks;
        // prefer IPv4 for the fallback without affecting normal FreshRSS fetching.
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        $this->applyEntryNetworkOptions($options, $entry);

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        $body = is_string($body) ? $body : '';
        $body = $this->normalizeFetchedHtmlEncoding($body);

        return [
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'effective_url' => $effective !== '' ? $effective : $pageUrl,
            'method' => 'direct-curl',
        ];
    }

    /** @param array<int,mixed> $options */
    private function applyEntryNetworkOptions(array &$options, ?FreshRSS_Entry $entry): void
    {
        $feed = $entry?->feed();
        if (!($feed instanceof FreshRSS_Feed)) {
            return;
        }

        $attributes = $feed->attributes();
        $params = FreshRSS_http_Util::sanitizeCurlParams(
            is_array($attributes['curl_params'] ?? null) ? $attributes['curl_params'] : []
        );
        foreach ([CURLOPT_COOKIE, CURLOPT_PROXY, CURLOPT_PROXYTYPE] as $key) {
            if (array_key_exists($key, $params)) {
                $options[$key] = $params[$key];
            }
        }
        foreach ($feed->curlOptions() as $key => $value) {
            if (in_array($key, [CURLOPT_USERPWD, CURLOPT_HTTPAUTH, CURLOPT_PROXY, CURLOPT_PROXYTYPE], true)) {
                $options[$key] = $value;
            }
        }
    }

    private function decodeWindows1251(string $body): string
    {
        if ($body === '') {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $body);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $body;
    }

    /** @param list<string> $existing
     *  @param array<string,string> $wanted
     *  @return list<string>
     */
    private function mergeRequestHeaders(array $existing, array $wanted): array
    {
        $result = [];
        $seen = [];
        foreach ($existing as $header) {
            if (!is_string($header) || trim($header) === '') {
                continue;
            }
            $name = strtolower(trim(strtok($header, ':') ?: ''));
            if ($name !== '') {
                $seen[$name] = true;
            }
            $result[] = $header;
        }
        foreach ($wanted as $name => $value) {
            if (!isset($seen[strtolower($name)])) {
                $result[] = $name . ': ' . $value;
            }
        }
        return $result;
    }

    private function normalizeFetchedHtmlEncoding(string $body): string
    {
        if ($body === '' || !function_exists('mb_check_encoding') || mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        if (function_exists('mb_convert_encoding')) {
            foreach (['Windows-1251', 'KOI8-R', 'ISO-8859-1'] as $encoding) {
                $converted = @mb_convert_encoding($body, 'UTF-8', $encoding);
                if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }
        }
        return $body;
    }

    /**
     * @param list<array{body:string,status:int,error:string,effective_url:string,method:string}> $attempts
     */
    private function writeDiagnostic(string $url, array $attempts, int $images, string $result): void
    {
        $summary = [];
        foreach ($attempts as $attempt) {
            $body = $attempt['body'];
            $summary[] = [
                'method' => $attempt['method'],
                'status' => $attempt['status'],
                'bytes' => strlen($body),
                'effective_url' => $attempt['effective_url'],
                'error' => $attempt['error'],
                'markers' => [
                    'post_body' => stripos($body, 'post_body') !== false,
                    'postImg' => stripos($body, 'postImg') !== false,
                    'login' => stripos($body, 'login.php') !== false,
                    'captcha' => stripos($body, 'captcha') !== false,
                    'cloudflare' => $this->looksLikeCloudflareChallenge($body),
                ],
            ];
        }
        $payload = json_encode([
            'time' => time(),
            'url' => $url,
            'result' => $result,
            'images' => $images,
            'attempts' => $summary,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (is_string($payload)) {
            @file_put_contents($this->diagnosticFile(), $payload, LOCK_EX);
        }
    }

    /** @return array<string,mixed>|null */
    public function getLastDiagnostic(): ?array
    {
        $raw = @file_get_contents($this->diagnosticFile());
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function diagnosticFile(): string
    {
        $dir = DATA_PATH . '/cache/f95-full-images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir . '/last-diagnostic.json';
    }

    private function clearCache(): void
    {
        $dir = DATA_PATH . '/cache/f95-full-images';
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.json', GLOB_NOSORT);
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /** @return list<string> */
    private function extractTorrentPierFirstPostImages(string $html, string $pageUrl): array
    {
        if (!class_exists('DOMDocument')) {
            return $this->extractTorrentPierFirstPostImagesRegex($html, $pageUrl);
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $this->extractTorrentPierFirstPostImagesRegex($html, $pageUrl);
        }

        $xpath = new DOMXPath($dom);

        // TorrentPier layout used by Tapochek, RuTracker and Pornolab.
        // Prefer the first post under #topic_main, then the first tbody#post_* body,
        // finally fall back to the first .post_body/.postbody on the page.
        $body = $xpath->query('(//*[@id="topic_main"]//*[contains(concat(" ", normalize-space(@class), " "), " post_body ")])[1]')->item(0);
        if (!($body instanceof DOMElement)) {
            $body = $xpath->query('(//tbody[starts-with(@id, "post_")][1]//*[contains(concat(" ", normalize-space(@class), " "), " post_body ")])[1]')->item(0);
        }
        if (!($body instanceof DOMElement)) {
            $body = $xpath->query('(//*[contains(concat(" ", normalize-space(@class), " "), " post_body ")])[1]')->item(0);
        }
        if (!($body instanceof DOMElement)) {
            $body = $xpath->query('(//*[contains(concat(" ", normalize-space(@class), " "), " postbody ")])[1]')->item(0);
        }
        if ($body instanceof DOMElement) {
            $images = $this->collectImagesFromNode($xpath, $body, $pageUrl);
            if ($images !== []) {
                return $images;
            }
        }

        // RuTracker has changed its HTML details a few times.  Its stable
        // marker is the <!--/post_body--> comment and lazy images are often
        // <var title="FULL_URL">, sometimes without the postImg class.
        return $this->extractTorrentPierFirstPostImagesRegex($html, $pageUrl);
    }

    /** @return list<string> */
    private function extractGenericPageImages(string $html, string $pageUrl): array
    {
        if (!class_exists('DOMDocument')) {
            return [];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $queries = [
            '(//*[@itemprop="articleBody"])[1]',
            '(//*[contains(concat(" ", normalize-space(@class), " "), " post_body ")])[1]',
            '(//*[contains(concat(" ", normalize-space(@class), " "), " postbody ")])[1]',
            '(//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")])[1]',
            '(//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")])[1]',
            '(//article)[1]',
            '(//main)[1]',
        ];

        $body = null;
        foreach ($queries as $query) {
            $candidate = $xpath->query($query)->item(0);
            if ($candidate instanceof DOMElement) {
                $body = $candidate;
                break;
            }
        }

        if (!($body instanceof DOMElement)) {
            return [];
        }

        return $this->collectImagesFromNode($xpath, $body, $pageUrl);
    }

    /** @return list<string> */
    private function collectImagesFromNode(DOMXPath $xpath, DOMElement $body, string $pageUrl): array
    {
        $images = [];

        // Full-size image is very often the href around a thumbnail/placeholder.
        $anchors = $xpath->query('.//a[@href][.//img or .//var[contains(concat(" ", normalize-space(@class), " "), " postImg ")]]', $body);
        if ($anchors instanceof DOMNodeList) {
            foreach ($anchors as $anchor) {
                if (!($anchor instanceof DOMElement)) {
                    continue;
                }
                $url = $this->normalizeGenericImageUrl($anchor->getAttribute('href'), $pageUrl);
                if ($url !== null) {
                    $url = $this->resolveRemoteImageUrl($url);
                }
                if ($url !== null && $this->looksLikeDirectImageUrl($url) && !$this->looksLikeUiImage($url)) {
                    $images[$url] = true;
                    continue;
                }

                if ($url !== null) {
                    $resolved = $this->resolveImageViewerUrl($url);
                    if ($resolved !== null && !$this->looksLikeUiImage($resolved)) {
                        $images[$resolved] = true;
                    }
                }
            }
        }

        // TorrentPier/RuTracker lazy image placeholder.  Older/newer layouts
        // may omit the postImg class, but keep the original image in title/data-*.
        $vars = $xpath->query('.//var[@title or @data-src or @data-url]', $body);
        if ($vars instanceof DOMNodeList) {
            foreach ($vars as $var) {
                if (!($var instanceof DOMElement)) {
                    continue;
                }
                foreach (['title', 'data-src', 'data-url'] as $attribute) {
                    $url = $this->normalizeGenericImageUrl($var->getAttribute($attribute), $pageUrl);
                    if ($url !== null) {
                        $url = $this->resolveRemoteImageUrl($url);
                    }
                    // postImg is itself an image placeholder, so the URL does not
                    // need to end in .jpg/.png (some image hosts use opaque URLs).
                    if ($url !== null && !$this->looksLikeUiImage($url)) {
                        $images[$url] = true;
                        break;
                    }
                }
            }
        }

        $imgNodes = $xpath->query('.//img', $body);
        if ($imgNodes instanceof DOMNodeList) {
            foreach ($imgNodes as $img) {
                if (!($img instanceof DOMElement)) {
                    continue;
                }

                foreach (['data-original', 'data-lazy-src', 'data-src', 'data-url', 'src'] as $attribute) {
                    $url = $this->normalizeGenericImageUrl($img->getAttribute($attribute), $pageUrl);
                    if ($url !== null) {
                        $url = $this->resolveRemoteImageUrl($url);
                    }
                    // An actual <img> source is already strong evidence that this is
                    // an image even when the CDN URL has no file extension.
                    if ($url !== null && !$this->looksLikeUiImage($url)) {
                        $images[$url] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($images);
    }

    private function topicReferer(string $pageUrl): string
    {
        $parts = parse_url($pageUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return $pageUrl;
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '/');
        $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
        return $scheme . '://' . $parts['host'] . $port . $dir;
    }

    /** @return list<string> */
    private function extractTorrentPierFirstPostImagesRegex(string $html, string $pageUrl): array
    {
        $post = $html;

        // Prefer the first real post body. RuTracker closes it with this comment.
        if (preg_match("~<div\\b[^>]*class=([\"'])[^\"']*\\bpost_body\\b[^\"']*\\1[^>]*>(.*?)<!--\\s*/post_body\\s*-->~is", $html, $m)) {
            $post = $m[2];
        } elseif (preg_match("~<table\\b[^>]*id=([\"'])topic_main\\1[^>]*>(.*?)</table>~is", $html, $m)) {
            $post = $m[2];
        } elseif (preg_match("~<div\\b[^>]*class=([\"'])[^\"']*\\bpost_body\\b[^\"']*\\1[^>]*>~is", $html, $m, PREG_OFFSET_CAPTURE)) {
            // Current RuTracker HTML can omit/change the historical closing comment.
            // Search a generous bounded region beginning at the first post_body.
            $offset = (int)$m[0][1];
            $post = substr($html, $offset, 600000);
        }

        $images = [];
        $add = function (string $candidate, bool $requireImageExtension = false) use (&$images, $pageUrl): void {
            $url = $this->normalizeGenericImageUrl($candidate, $pageUrl);
            if ($url === null || $this->looksLikeUiImage($url)) {
                return;
            }
            $url = $this->resolveRemoteImageUrl($url);
            if ($requireImageExtension && !$this->looksLikeDirectImageUrl($url)) {
                return;
            }
            $images[$url] = true;
        };

        // TorrentPier/RuTracker lazily renders release images from <var title="URL">.
        if (preg_match_all("~<var\\b[^>]*(?:title|data-src|data-url)=([\"'])(.*?)\\1[^>]*>~is", $post, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $add($m[2], false);
            }
        }

        // Standard and lazy <img> attributes.
        if (preg_match_all("~<img\\b[^>]*(?:data-original|data-lazy-src|data-src|data-url|src)=([\"'])(.*?)\\1[^>]*>~is", $post, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $add($m[2], false);
            }
        }

        // srcset/data-srcset: take every candidate; duplicates are removed below.
        if (preg_match_all("~<(?:img|source)\\b[^>]*(?:srcset|data-srcset)=([\"'])(.*?)\\1[^>]*>~is", $post, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                foreach (preg_split('/\\s*,\\s*/', $m[2]) ?: [] as $candidate) {
                    $candidate = preg_replace('/\\s+\\d+(?:\\.\\d+)?[wx]\\s*$/i', '', trim($candidate)) ?? trim($candidate);
                    if ($candidate !== '') {
                        $add($candidate, false);
                    }
                }
            }
        }

        // Direct original links around thumbnails.
        if (preg_match_all("~<a\\b[^>]*href=([\"'])(.*?)\\1[^>]*>~is", $post, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $viewer = $this->normalizeGenericImageUrl($m[2], $pageUrl);
                $resolved = $viewer !== null ? $this->resolveImageViewerUrl($viewer) : null;
                $add($resolved ?? $m[2], true);
            }
        }

        return array_keys($images);
    }

    private function normalizeGenericImageUrl(string $url, string $baseUrl): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $base = parse_url($baseUrl);
            $scheme = is_array($base) ? strtolower((string)($base['scheme'] ?? 'https')) : 'https';
            $url = $scheme . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $base = parse_url($baseUrl);
            if (!is_array($base) || empty($base['host'])) {
                return null;
            }
            $scheme = (string)($base['scheme'] ?? 'https');
            $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
            $url = $scheme . '://' . $base['host'] . $port . $url;
        } elseif (!preg_match('~^https?://~i', $url)) {
            $base = parse_url($baseUrl);
            if (!is_array($base) || empty($base['host'])) {
                return null;
            }
            $scheme = (string)($base['scheme'] ?? 'https');
            $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
            $path = (string)($base['path'] ?? '/');
            $dir = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
            $url = $scheme . '://' . $base['host'] . $port . $dir . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return $this->preferOriginalImageUrl($url);
    }

    private function looksLikeDirectImageUrl(string $url): bool
    {
        return FullImagesScrapperUrlRules::looksLikeDirectImageUrl($url);
    }

    private function looksLikeUiImage(string $url): bool
    {
        $lower = strtolower($url);
        foreach ([
            '/templates/', '/smiles/', '/smilies/', '/emoji/', '/avatars/', '/avatar/',
            '/ranks/', '/flags/', '/forum/images/', 'rutrk.org',
            'favicon', 'icon_', '/icons/', '/logo', 'counter.', 'mc.yandex.', 'adriver.',
            'pixel.', 'spacer.gif', 'blank.gif', 'transparent.gif', 'attach_big.gif',
            'magnet_1.svg', 'reply.gif', '/ads/',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $remoteImages
     */
    private function appendMissingGenericImages(string $html, array $remoteImages, string $baseUrl): string
    {
        $existing = $this->existingGenericImageUrls($html, $baseUrl);
        $missing = [];

        foreach ($remoteImages as $url) {
            $normalized = $this->normalizeGenericImageUrl($url, $baseUrl);
            if ($normalized !== null && !isset($existing[$normalized])) {
                $missing[] = $normalized;
            }
        }

        if ($missing === []) {
            return $html;
        }

        $gallery = $this->buildGallery($missing);
        return $existing === [] ? $gallery . $html : $html . $gallery;
    }

    /** @return array<string, true> */
    private function existingGenericImageUrls(string $html, string $baseUrl): array
    {
        $result = [];

        if (class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="rss-existing-root">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded) {
                $xpath = new DOMXPath($dom);
                foreach (['//*[@id="rss-existing-root"]//img[@src]', '//*[@id="rss-existing-root"]//a[@href]'] as $query) {
                    $nodes = $xpath->query($query);
                    if (!($nodes instanceof DOMNodeList)) {
                        continue;
                    }
                    foreach ($nodes as $node) {
                        if (!($node instanceof DOMElement)) {
                            continue;
                        }
                        $attr = strtolower($node->tagName) === 'img' ? 'src' : 'href';
                        $url = $this->normalizeGenericImageUrl($node->getAttribute($attr), $baseUrl);
                        if ($url !== null && $this->looksLikeDirectImageUrl($url) && !$this->looksLikeUiImage($url)) {
                            $result[$url] = true;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function getThreadImages(string $threadUrl): array
    {
        $cacheFile = $this->cacheFile($threadUrl);
        $stale = $this->readCache($cacheFile, false);
        $fresh = $this->readCache($cacheFile, true);
        if ($fresh !== null) {
            return $fresh;
        }

        $result = FreshRSS_http_Util::httpGet(
            $threadUrl,
            null,
            'html',
            [
                'timeout' => self::FETCH_TIMEOUT,
                'curl_params' => [
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_HTTPHEADER => [
                        'Accept-Language: en-US,en;q=0.9',
                        'Cache-Control: no-cache',
                    ],
                ],
            ]
        );

        $body = is_string($result['body'] ?? null) ? $result['body'] : '';
        $status = is_int($result['status'] ?? null) ? $result['status'] : 0;
        $failed = (bool)($result['fail'] ?? true);

        if ($failed || $body === '' || ($status > 0 && $status >= 400)) {
            $this->writeCache($cacheFile, [], false);
            Minz_Log::warning('FullImagesScrapper: failed to fetch ' . $threadUrl . ' (HTTP ' . $status . ')');
            return $stale ?? [];
        }

        $images = $this->extractImagesFromFirstPost($body);
        if ($images === []) {
            $this->writeCache($cacheFile, [], false);
            Minz_Log::warning('FullImagesScrapper: no attachment images found in ' . $threadUrl . ' (possible anti-bot page or changed HTML)');
            return $stale ?? [];
        }

        $this->writeCache($cacheFile, $images, true);
        return $images;
    }

    /** @return list<string> */
    private function extractImagesFromFirstPost(string $html): array
    {
        if (!class_exists('DOMDocument')) {
            return $this->extractImagesFromFirstPostRegex($html);
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);

        // XenForo/F95Zone: first thread post.
        $post = $xpath->query('(//article[contains(concat(" ", normalize-space(@class), " "), " message--post ")])[1]')->item(0);
        if (!($post instanceof DOMElement)) {
            $post = $xpath->query('(//article[contains(@class, "message") and @data-content])[1]')->item(0);
        }
        if (!($post instanceof DOMElement)) {
            return [];
        }

        // Restrict extraction to the post body, excluding avatar/sidebar/signature where possible.
        $body = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " bbWrapper ")][1]', $post)->item(0);
        if (!($body instanceof DOMElement)) {
            $body = $post;
        }

        $images = [];

        // Best source: links wrapping images. F95Zone puts the full/original URL in href.
        $anchors = $xpath->query('.//a[@href][.//img]', $body);
        if ($anchors instanceof DOMNodeList) {
            foreach ($anchors as $anchor) {
                if (!($anchor instanceof DOMElement)) {
                    continue;
                }
                $url = $this->normalizeAttachmentUrl($anchor->getAttribute('href'));
                if ($url !== null) {
                    $images[$url] = true;
                }
            }
        }

        // Fallback for attachment images without a wrapping link.
        $imgNodes = $xpath->query('.//img', $body);
        if ($imgNodes instanceof DOMNodeList) {
            foreach ($imgNodes as $img) {
                if (!($img instanceof DOMElement)) {
                    continue;
                }
                foreach (['data-url', 'data-src', 'src'] as $attribute) {
                    $url = $this->normalizeAttachmentUrl($img->getAttribute($attribute));
                    if ($url !== null) {
                        $images[$url] = true;
                        break;
                    }
                }
            }
        }

        return array_keys($images);
    }

    /**
     * Replace one or more literal XenForo [ATTACH] placeholders with images missing from the RSS body.
     * If XenForo truncated the placeholder (no closing [/ATTACH]), the fallback pattern handles that too.
     *
     * @param list<string> $remoteImages
     */
    private function replaceAttachPlaceholders(string $html, array $remoteImages): string
    {
        $existing = $this->existingAttachmentUrls($html);
        $missing = [];

        foreach ($remoteImages as $url) {
            $normalized = $this->normalizeAttachmentUrl($url);
            if ($normalized !== null && !isset($existing[$normalized])) {
                $missing[$normalized] = true;
            }
        }

        $missingUrls = array_keys($missing);
        $gallery = $this->buildGallery($missingUrls);

        // Complete BBCode first; fallback also covers the truncated text seen in some XenForo RSS items.
        $patterns = [
            '~\\[ATTACH\\b[^\\]]*\\].*?\\[/ATTACH\\]~is',
            '~\\[ATTACH\\b[^<\\r\\n]{0,1000}~i',
        ];

        $replacedAny = false;
        foreach ($patterns as $pattern) {
            $first = true;
            $newHtml = preg_replace_callback(
                $pattern,
                static function (array $match) use (&$first, $gallery, &$replacedAny): string {
                    $replacedAny = true;
                    if ($first) {
                        $first = false;
                        return $gallery;
                    }
                    return '';
                },
                $html
            );
            if (is_string($newHtml) && $newHtml !== $html) {
                $html = $newHtml;
                break;
            }
        }

        return $replacedAny ? $html : $html;
    }

    /**
     * In force mode, make sure originals from the first post are present even when
     * the RSS item contains no literal [ATTACH] placeholder. Existing images are
     * deduplicated, so the configured limit is a limit on the first-post image list,
     * not an "extra images" count.
     *
     * @param list<string> $remoteImages
     */
    private function appendMissingImages(string $html, array $remoteImages): string
    {
        $existing = $this->existingAttachmentUrls($html);
        $missing = [];

        foreach ($remoteImages as $url) {
            $normalized = $this->normalizeAttachmentUrl($url);
            if ($normalized !== null && !isset($existing[$normalized])) {
                $missing[] = $normalized;
            }
        }

        if ($missing === []) {
            return $html;
        }

        $gallery = $this->buildGallery($missing);

        // If the RSS already has an F95 image, keep it in its original position and
        // add the remaining originals after the current RSS content. If it has no image,
        // put the gallery first so the article immediately gets a proper preview.
        if ($existing !== []) {
            return $html . $gallery;
        }

        return $gallery . $html;
    }

    /** @param list<string> $urls */
    private function buildGallery(array $urls): string
    {
        $gallery = '';
        foreach ($urls as $url) {
            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $gallery .= '<p class="f95-full-image-wrap"><a href="' . $escaped . '" target="_blank" rel="noreferrer">'
                . '<img class="f95-full-image" src="' . $escaped . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"></a></p>';
        }
        return $gallery;
    }

    private function upgradeExistingThumbnailsRegex(string $html): string
    {
        $pattern = '~<a\b(?P<a1>[^>]*\bhref=(?P<q>["\'])(?P<href>https?://attachments\.f95zone\.to/[^"\']+)(?P=q)[^>]*)>(?P<middle>.*?<img\b(?P<img>[^>]*\bsrc=(?P<sq>["\'])https?://attachments\.f95zone\.to/[^"\']*/thumb/[^"\']+(?P=sq)[^>]*)>.*?)</a>~is';

        $out = preg_replace_callback($pattern, function (array $m): string {
            $full = $this->normalizeAttachmentUrl($m['href'] ?? '');
            if ($full === null) {
                return $m[0];
            }

            $escaped = htmlspecialchars($full, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $middle = $m['middle'];
            $middle = preg_replace(
                '~\bsrc=(["\'])https?://attachments\.f95zone\.to/[^"\']*/thumb/[^"\']+\1~i',
                'src="' . $escaped . '"',
                $middle,
                1
            ) ?? $middle;
            $middle = preg_replace('~\s(?:width|height|srcset|data-src|data-url)=(["\']).*?\1~i', '', $middle) ?? $middle;
            $middle = preg_replace_callback('~<img\b([^>]*)>~i', static function (array $imgMatch): string {
                $attrs = $imgMatch[1];
                if (preg_match('~\bclass=(["\'])(.*?)\1~i', $attrs, $classMatch)) {
                    if (!preg_match('~(?:^|\s)f95-full-image(?:\s|$)~', $classMatch[2])) {
                        $newClass = trim($classMatch[2] . ' f95-full-image');
                        $attrs = preg_replace(
                            '~\bclass=(["\'])(.*?)\1~i',
                            'class="' . htmlspecialchars($newClass, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"',
                            $attrs,
                            1
                        ) ?? $attrs;
                    }
                } else {
                    $attrs .= ' class="f95-full-image"';
                }
                return '<img' . $attrs . '>';
            }, $middle, 1) ?? $middle;

            return '<a' . $m['a1'] . '>' . $middle . '</a>';
        }, $html);

        return is_string($out) ? $out : $html;
    }

    /** @return list<string> */
    private function extractImagesFromFirstPostRegex(string $html): array
    {
        $start = stripos($html, 'message--post');
        if ($start !== false) {
            $next = stripos($html, 'message--post', $start + 13);
            $html = $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
        }

        $images = [];
        if (preg_match_all('~https?://attachments\.f95zone\.to/[^\s"\'<>]+~i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $normalized = $this->normalizeAttachmentUrl($url);
                if ($normalized !== null) {
                    $images[$normalized] = true;
                }
            }
        }

        if (preg_match_all('~(?:https?://(?:www\.)?f95zone\.to)?/attachments/[^\s"\'<>]+~i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $normalized = $this->normalizeAttachmentUrl($url);
                if ($normalized !== null) {
                    $images[$normalized] = true;
                }
            }
        }

        return array_keys($images);
    }

    /** @return array<string, true> */
    private function existingAttachmentUrls(string $html): array
    {
        $result = [];
        if (preg_match_all('~https?://attachments\\.f95zone\\.to/[^\\s"\'<>]+~i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $normalized = $this->normalizeAttachmentUrl(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($normalized !== null) {
                    $result[$normalized] = true;
                }
            }
        }
        return $result;
    }

    private function normalizeAttachmentUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://f95zone.to' . $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if ($host === 'attachments.f95zone.to') {
            // /thumb/ is a generated thumbnail directory. The same filename one level up is the original file.
            $url = preg_replace('~/thumb/~i', '/', $url, 1) ?? $url;
            return $url;
        }

        if (in_array($host, ['f95zone.to', 'www.f95zone.to'], true) && str_contains($path, '/attachments/')) {
            return $url;
        }

        return null;
    }

    private function appendClass(DOMElement $element, string $class): void
    {
        $classes = preg_split('/\\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array($class, $classes, true)) {
            $classes[] = $class;
            $element->setAttribute('class', implode(' ', $classes));
        }
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?: '';
        }
        return $html;
    }

    private function cacheFile(string $key): string
    {
        $dir = DATA_PATH . '/cache/f95-full-images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    /** @return list<string>|null */
    private function readCache(string $file, bool $requireFresh): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['images'] ?? null)) {
            return null;
        }

        $ok = (bool)($data['ok'] ?? false);
        if ($requireFresh) {
            $mtime = @filemtime($file) ?: 0;
            $ttl = $ok ? self::CACHE_TTL_OK : self::CACHE_TTL_FAIL;
            if ($mtime <= 0 || $mtime < time() - $ttl) {
                return null;
            }
        }

        $images = [];
        foreach ($data['images'] as $url) {
            if (!is_string($url)) {
                continue;
            }

            $query = (string)(parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY) ?? '');
            parse_str($query, $queryParams);
            if (isset($queryParams['expires']) && is_numeric($queryParams['expires'])
                && (int)$queryParams['expires'] <= time() + 300) {
                // Rebuild the entire gallery; otherwise a still-valid cover alone
                // would make expired screenshots look like a successful cache hit.
                return null;
            }

            $normalized = $this->normalizeCachedImageUrl($url);
            if ($normalized !== null) {
                $images[$normalized] = true;
            }
        }

        return array_keys($images);
    }

    private function normalizeCachedImageUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return null;
        }

        // Preserve the F95-specific /thumb/ -> original normalization.
        $attachment = $this->normalizeAttachmentUrl($url);
        if ($attachment !== null) {
            return $attachment;
        }

        // Generic/TorrentPier cache entries are stored as absolute URLs.
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $this->preferOriginalImageUrl($url);
    }

    /** @param list<string> $images */
    private function writeCache(string $file, array $images, bool $ok): void
    {
        $payload = json_encode(
            ['ok' => $ok, 'images' => array_values($images)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (is_string($payload)) {
            @file_put_contents($file, $payload, LOCK_EX);
        }
    }
}
