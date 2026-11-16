<?php

declare(strict_types=1);

/** Pure, offline-tested URL recognition and normalization rules. */
final class FullImagesScrapperUrlRules
{
    public static function isF95ThreadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');

        return in_array($host, ['f95zone.to', 'www.f95zone.to'], true)
            && str_starts_with($path, '/threads/');
    }

    public static function isTorrentPierThreadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = strtolower((string)($parts['path'] ?? ''));
        $basename = basename($path);

        if ($basename !== 'viewtopic.php') {
            return false;
        }

        // Tapochek, Pornolab and RuTracker all use the TorrentPier/phpBB-style
        // topic layout with the first release post in .post_body and lazy
        // images represented as <var class="postImg" title="FULL_URL">.
        if (in_array($host, [
            'tapochek.net', 'www.tapochek.net',
            'pornolab.net', 'www.pornolab.net',
            'nnmclub.to', 'www.nnmclub.to',
            'rutracker.org', 'www.rutracker.org',
            'rutracker.net', 'www.rutracker.net',
            'rutracker.nl', 'www.rutracker.nl',
            'rutracker.cr', 'www.rutracker.cr',
        ], true)) {
            return true;
        }

        return false;
    }

    public static function isRutrackerThreadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = strtolower((string)($parts['path'] ?? ''));
        if (basename($path) !== 'viewtopic.php') {
            return false;
        }

        return in_array($host, [
            'rutracker.org', 'www.rutracker.org',
            'rutracker.net', 'www.rutracker.net',
            'rutracker.nl', 'www.rutracker.nl',
            'rutracker.cr', 'www.rutracker.cr',
        ], true);
    }

    public static function isFetchableHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return !in_array($host, ['localhost', 'localhost.localdomain'], true);
    }

    public static function normalizeSolverrUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            $url = rtrim($url, '/') . '/v1';
        }
        return $url;
    }

    public static function preferOriginalImageUrl(string $url): string
    {
        // Fastpic uses a stable pair of paths for its generated previews and
        // originals.  RuTracker's postImg placeholders expose only the preview,
        // while the surrounding link points to an HTML viewer page.  Convert
        // that confirmed CDN form to the original image URL instead of rendering
        // the small preview in FreshRSS.
        if (preg_match('~^https?://i\d+\.fastpic\.org/thumb/~i', $url)) {
            $url = preg_replace(
                '~^(https?://i\d+\.fastpic\.org)/thumb/~i',
                '$1/big/',
                $url,
            ) ?? $url;
            $url = preg_replace('~\.jpeg(?=([?#]|$))~i', '.jpg', $url) ?? $url;
        }

        return $url;
    }

    public static function looksLikeDirectImageUrl(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        return (bool)preg_match('~\.(?:jpe?g|png|webp|gif|avif|bmp)(?:$|/)~i', $path);
    }
}
