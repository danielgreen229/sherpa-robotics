<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Support\Config;

final class ImageDownloader implements ImageFetcher
{
    /** @var array<string, array{url: string, path: string}> */
    private array $cache = [];

    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function fetch(string $url): array
    {
        if (isset($this->cache[$url]) && is_file(Config::storage() . $this->cache[$url]['path'])) {
            return $this->cache[$url];
        }
        if (count($this->cache) >= 1000) {
            $this->cache = [];
        }
        $known = $this->products->imagePath($url);
        if ($known !== null && is_file(Config::storage() . $known)) {
            return $this->cache[$url] = ['url' => $url, 'path' => $known];
        }
        $original = $url;
        $body = '';
        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            [$host, $port, $ip] = $this->validateUrl($url);
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Не удалось инициализировать скачивание.');
            }
            $body = '';
            $location = null;
            curl_setopt_array($curl, [
                CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip], CURLOPT_USERAGENT => 'CatalogImporter/1.0',
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                    if (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 5 * 1024 * 1024) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($ok === false) {
                throw new \RuntimeException('Изображение недоступно, превышен размер 5 МБ или таймаут 8 секунд.');
            }
            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = $this->redirectUrl($url, $location);
                continue;
            }
            if ($status !== 200) {
                throw new \RuntimeException('Сервер изображения вернул HTTP ' . $status . '.');
            }
            break;
        }
        if ($status !== 200) {
            throw new \RuntimeException('Слишком много перенаправлений изображения.');
        }
        $path = (new ImageStorage())->save($body);
        return $this->cache[$original] = ['url' => $original, 'path' => $path];
    }

    /** @return array{string, int, string} */
    public function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'], $parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Разрешены только публичные HTTP/HTTPS ссылки без авторизации.');
        }
        $host = $parts['host'];
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true) || str_contains($host, ':')) {
            throw new \RuntimeException('Недопустимый адрес или порт изображения.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$ips) {
            throw new \RuntimeException('Не удалось определить адрес сервера изображения.');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIpv4($ip)) {
                throw new \RuntimeException('Скачивание из локальных и служебных сетей запрещено.');
            }
        }
        return [$host, $port, $ips[0]];
    }

    private function isPublicIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $number = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($number & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }
        return true;
    }

    private function redirectUrl(string $from, string $to): string
    {
        if (preg_match('~^https?://~i', $to)) {
            return $to;
        }
        $parts = parse_url($from);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Некорректная база перенаправления.');
        }
        if (str_starts_with($to, '//')) {
            return $parts['scheme'] . ':' . $to;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($to, '?')) {
            return $origin . ($parts['path'] ?? '/') . $to;
        }
        if (str_starts_with($to, '#')) {
            return explode('#', $from, 2)[0] . $to;
        }
        return $origin . (str_starts_with($to, '/') ? $to : rtrim(dirname($parts['path'] ?? '/'), '/') . '/' . $to);
    }
}
