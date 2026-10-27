<?php

use App\Domain\Auth\DeviceLabel;

// Libellé des appareils connectés (contrat du lot 8, §5, I-110), sur des
// en-têtes User-Agent réels.

it('reads the browser and the system of real user agents', function (string $userAgent, string $label) {
    expect(DeviceLabel::fromUserAgent($userAgent))->toBe($label);
})->with([
    'Chrome, Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', 'Chrome sur Android'],
    'Chrome, Android (WebView)' => ['Mozilla/5.0 (Linux; Android 13; SM-A135F Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36', 'Chrome sur Android'],
    'Samsung Internet, Android' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet sur Android'],
    'Firefox, Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0', 'Firefox sur Android'],
    'Opera, Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36 OPR/84.0.0.0', 'Opera sur Android'],
    'Edge, Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36 EdgA/129.0.0.0', 'Edge sur Android'],
    'Chrome, Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome sur Windows'],
    'Edge, Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0', 'Edge sur Windows'],
    'Firefox, Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox sur Windows'],
    'Opera, Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera sur Windows'],
    'Safari, macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'Safari sur macOS'],
    'Chrome, macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome sur macOS'],
    'Firefox, macOS' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.7; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox sur macOS'],
    'Firefox, Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox sur Linux'],
    'Chrome, Linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome sur Linux'],
    'Safari, iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari sur iPhone'],
    'Chrome, iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', 'Chrome sur iPhone'],
    'Firefox, iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15', 'Firefox sur iPhone'],
    'Edge, iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/129.2792.84 Mobile/15E148 Safari/605.1.15', 'Edge sur iPhone'],
    // Application installée sur l'écran d'accueil : WebKit sans jeton « Safari/ ».
    'installed app, iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'Safari sur iPhone'],
    'Safari, iPad' => ['Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1', 'Safari sur iPad'],
]);

it('falls back on an unknown browser', function (?string $userAgent, string $label) {
    expect(DeviceLabel::fromUserAgent($userAgent))->toBe($label);
})->with([
    'missing' => [null, 'Navigateur inconnu'],
    'empty' => ['  ', 'Navigateur inconnu'],
    'tool' => ['curl/8.5.0', 'Navigateur inconnu'],
    'test client' => ['Symfony', 'Navigateur inconnu'],
    'unknown browser, known system' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Trident/7.0; rv:11.0', 'Navigateur inconnu sur Windows'],
    'known browser, unknown system' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome'],
]);
