<?php

declare(strict_types=1);

namespace Tests;

use App\Service\ImageDownloader;
use App\Service\ImageStorage;
use App\Service\ProductData;

final class ImageStorageTest extends DatabaseTestCase
{
    private function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    public function testImageContentIsSavedAndDeduplicatedByHash(): void
    {
        $storage = new ImageStorage();
        $bytes = $this->png();
        $path = $storage->save($bytes);
        self::assertSame($bytes, file_get_contents($this->temp . $path));
        self::assertSame('/media/' . hash('sha256', $bytes) . '.png', $path);
        self::assertSame($path, $storage->save($bytes));
        self::assertCount(1, glob($this->temp . '/media/*'));
    }

    public function testHtmlDisguisedAsImageIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageStorage())->save('<html>Not an image</html>');
    }

    public function testSvgIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageStorage())->save('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    }

    public function testOversizeImageIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageStorage())->save(str_repeat('x', 5 * 1024 * 1024 + 1));
    }

    public function testKnownImageIsReusedWithoutNetworkDownload(): void
    {
        $path = (new ImageStorage())->save($this->png());
        $data = ProductData::fromArray(['external_code' => 'image-cache', 'name' => 'Cached', 'price' => '100', 'purchase_price' => '50']);
        $this->products->save($data, [['url' => 'https://example.invalid/photo.png', 'path' => $path]]);
        $result = (new ImageDownloader($this->products))->fetch('https://example.invalid/photo.png');
        self::assertSame($path, $result['path']);
    }
    public function testDownloaderCacheCannotReuseDeletedFileAfterReset(): void
    {
        $path = (new ImageStorage())->save($this->png());
        $url = 'http://127.0.0.1/old.png';
        $data = ProductData::fromArray(['external_code' => 'old-cache', 'name' => 'Cached', 'price' => '100', 'purchase_price' => '50']);
        $this->products->save($data, [['url' => $url, 'path' => $path]]);
        $downloader = new ImageDownloader($this->products);
        self::assertSame($path, $downloader->fetch($url)['path']);
        (new \App\Runtime($this->database))->catalogReset->clear(1);
        self::assertFileDoesNotExist($this->temp . $path);
        $this->expectException(\RuntimeException::class);
        $downloader->fetch($url);
    }

}
