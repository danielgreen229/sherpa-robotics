<?php

declare(strict_types=1);

namespace Tests;

use App\Message\ImportMessage;
use App\Message\ImportSerializer;
use App\Service\ImageDownloader;
use App\Service\ProductData;
use App\Support\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class ValidationTest extends DatabaseTestCase
{
    public static function invalidPrices(): array
    {
        return [['-10'], ['abc'], ['1.999'], ['1e5'], [''], [null], [[]], ['1000000000000']];
    }

    #[DataProvider('invalidPrices')]
    public function testInvalidPrices(mixed $value): void
    {
        $this->expectException(ApiException::class);
        ProductData::money($value, 'Цена');
    }

    public function testMoneyNormalizationAndDiscountRounding(): void
    {
        self::assertSame('1234.50', ProductData::money("1\u{00A0}234,50", 'Цена'));
        $data = ProductData::fromArray(['external_code' => '001', 'name' => 'Товар', 'price' => '3', 'purchase_price' => '1']);
        self::assertSame('001', $data->externalCode);
        self::assertSame('66.67', $data->discount);
        $negative = ProductData::fromArray(['external_code' => '002', 'name' => 'Товар', 'price' => '3', 'purchase_price' => '4']);
        self::assertSame('-33.33', $negative->discount);
    }

    public function testZeroSalePriceRejected(): void
    {
        $this->expectException(ApiException::class);
        ProductData::fromArray(['external_code' => 'x', 'name' => 'Товар', 'price' => '0', 'purchase_price' => '0']);
    }

    public static function privateUrls(): array
    {
        return [['http://127.0.0.1/x.jpg'], ['http://10.0.0.1/x.jpg'], ['http://169.254.169.254/latest/meta-data'], ['file:///etc/passwd'], ['ftp://example.com/a'], ['http://user:pass@8.8.8.8/x'], ['http://8.8.8.8:9000/x'], ['http://[::1]/x'], ['http://192.168.0.1/x'], ['http://100.127.255.254/x'], ['http://198.18.0.1/x'], ['http://224.0.0.1/x']];
    }

    #[DataProvider('privateUrls')]
    public function testDownloaderRejectsPrivateAndUnsupportedUrls(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        (new ImageDownloader($this->products))->validateUrl($url);
    }

    public function testMessengerJsonSerializationPreservesRetryCount(): void
    {
        $serializer = new ImportSerializer();
        $id = str_repeat('a', 32);
        $envelope = $serializer->decode($serializer->encode(new Envelope(new ImportMessage($id), [new RedeliveryStamp(2)])));
        self::assertSame($id, $envelope->getMessage()->jobId);
        self::assertSame(2, $envelope->last(RedeliveryStamp::class)->getRetryCount());
    }

    public function testMessengerRejectsInvalidMessagePayload(): void
    {
        $this->expectException(\Symfony\Component\Messenger\Exception\MessageDecodingFailedException::class);
        (new ImportSerializer())->decode(['body' => '{"job_id":"../../passwd"}']);
    }
}
