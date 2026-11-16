<?php

declare(strict_types=1);

// Offline regression harness: no FreshRSS installation or external HTTP requests.
class Minz_Extension {}

require_once dirname(__DIR__) . '/extension.php';

$ext = new FullImagesScrapperExtension();
$checks = 0;

function expect(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function callPrivate(object $instance, string $name, mixed ...$args): mixed
{
    $method = new ReflectionMethod($instance, $name);
    return $method->invoke($instance, ...$args);
}

expect(callPrivate($ext, 'isF95ThreadUrl', 'https://f95zone.to/threads/test.5/') === true, 'F95 thread');
expect(callPrivate($ext, 'isF95ThreadUrl', 'https://example.invalid/threads/test.5/') === false, 'F95 other host');
expect(callPrivate($ext, 'isTorrentPierThreadUrl', 'https://tapochek.net/viewtopic.php?t=25') === true, 'TorrentPier thread');
expect(callPrivate($ext, 'isTorrentPierThreadUrl', 'https://tapochek.net/viewforum.php?f=25') === false, 'TorrentPier forum');
expect(callPrivate($ext, 'isRutrackerThreadUrl', 'https://rutracker.org/forum/viewtopic.php?t=25') === true, 'RuTracker thread');
expect(callPrivate($ext, 'isRutrackerThreadUrl', 'https://rutracker.org.example.invalid/forum/viewtopic.php?t=25') === false, 'RuTracker host spoof');
expect(callPrivate($ext, 'isFetchableHttpUrl', 'https://example.org/topic') === true, 'HTTPS allowed');
expect(callPrivate($ext, 'isFetchableHttpUrl', 'file:///etc/passwd') === false, 'File URLs rejected');
expect(callPrivate($ext, 'isFetchableHttpUrl', 'http://localhost/') === false, 'Localhost rejected');
expect(callPrivate($ext, 'normalizeSolverrUrl', '127.0.0.1:8191') === 'http://127.0.0.1:8191/v1', 'Solverr URL');
expect(callPrivate($ext, 'preferOriginalImageUrl', 'https://i123.fastpic.org/thumb/a.jpeg') === 'https://i123.fastpic.org/big/a.jpg', 'Fastpic original');
expect(callPrivate($ext, 'preferOriginalImageUrl', 'https://img.example.org/a.png') === 'https://img.example.org/a.png', 'Non-Fastpic unchanged');
expect(callPrivate($ext, 'normalizeGenericImageUrl', '/img/a.png', 'https://example.org/topic/page') === 'https://example.org/img/a.png', 'Root relative URL');
expect(callPrivate($ext, 'normalizeGenericImageUrl', 'javascript:alert(1)', 'https://example.org/topic') === null, 'JavaScript URL rejected');
expect(callPrivate($ext, 'looksLikeDirectImageUrl', 'https://example.org/cover.webp?size=1') === true, 'Image extension');
expect(callPrivate($ext, 'looksLikeDirectImageUrl', 'https://example.org/viewer/123') === false, 'Viewer URL');

echo "PASS: {$checks} offline FullImagesScrapper checks\n";
