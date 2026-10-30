<?php

declare(strict_types=1);

namespace Tests;

use App\Message\ImportMessage;
use App\Service\ExportService;
use App\Service\ImageFetcher;
use App\Service\ImportProcessor;
use App\Service\ProductService;
use App\Service\XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ImportTest extends DatabaseTestCase
{
    private function processor(bool $failImages = false): ImportProcessor
    {
        $fetcher = new class ($this->temp, $failImages) implements ImageFetcher {
            public function __construct(private string $temp, private bool $fail)
            {
            }
            public function fetch(string $url): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('Image host unavailable');
                }
                $path = '/media/' . hash('sha256', $url) . '.png';
                file_put_contents($this->temp . $path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB9sAAAAASUVORK5CYII='));
                return ['url' => $url, 'path' => $path];
            }
        };
        return new ImportProcessor($this->database->connection(), $this->jobs, new ProductService($this->products, $fetcher), $this->products);
    }

    public function testOriginalFileImports40ProductsAndSecondImportUpdatesWithoutDuplicates(): void
    {
        $id = $this->jobs->create(1, 'products.xlsx', $this->example());
        ($this->processor())(new ImportMessage($id));
        $status = $this->jobs->status($id, 1);
        self::assertSame('completed', $status['status']);
        self::assertSame(40, $status['created']);
        self::assertSame(40, $status['processed']);
        self::assertSame(100, $status['progress']);
        self::assertSame([], $status['errors']);
        $db = $this->database->connection();
        $attributes = (int) $db->fetchOne('SELECT COUNT(*) FROM product_attributes');
        $images = (int) $db->fetchOne('SELECT COUNT(*) FROM product_images');
        self::assertSame(91, $images);
        self::assertGreaterThan(600, $attributes);
        $second = $this->jobs->create(1, 'again.xlsx', $this->example());
        ($this->processor())(new ImportMessage($second));
        $status = $this->jobs->status($second, 1);
        self::assertSame(0, $status['created']);
        self::assertSame(40, $status['updated']);
        self::assertSame(40, $this->products->paginate([])['total']);
        self::assertSame($attributes, (int) $db->fetchOne('SELECT COUNT(*) FROM product_attributes'));
        self::assertSame($images, (int) $db->fetchOne('SELECT COUNT(*) FROM product_images'));
        // Redelivery of a finished job leaves its receipts unchanged.
        ($this->processor())(new ImportMessage($second));
        self::assertSame(40, $this->jobs->status($second, 1)['processed']);
    }

    public function testBadRowDoesNotStopFollowingValidRows(): void
    {
        $file = $this->writeFile([
            ['bad', '', '10', '5'],
            ['valid', 'Корректный товар', '150,00', '100,00'],
            ['zero', 'Нулевая цена', '0', '0'],
            ['valid-2', 'Ещё один товар', '300', '100'],
        ]);
        $id = $this->jobs->create(1, 'mixed.xlsx', $file);
        ($this->processor())(new ImportMessage($id));
        $status = $this->jobs->status($id, 1);
        self::assertSame('completed', $status['status']);
        self::assertSame(2, $status['created']);
        self::assertSame(2, $status['invalid']);
        self::assertSame([2, 4], array_column($status['errors'], 'row'));
    }

    public function testUnavailableImagesBecomeWarningsAndKeepProducts(): void
    {
        $id = $this->jobs->create(1, 'products.xlsx', $this->example());
        ($this->processor(true))(new ImportMessage($id));
        $status = $this->jobs->status($id, 1);
        self::assertSame('completed', $status['status']);
        self::assertSame(40, $status['created']);
        self::assertSame(0, $status['invalid']);
        self::assertCount(91, $status['errors']);
        self::assertSame('warning', $status['errors'][0]['severity']);
    }

    public function testResumeSkipsCommittedRowReceipts(): void
    {
        $file = $this->writeFile([['existing', 'Уже обработан', '150', '100'], ['next', 'Следующий', '300', '100']]);
        $id = $this->jobs->create(1, 'resume.xlsx', $file);
        $this->jobs->start($id, 2);
        $this->jobs->record($id, 2, 'invalid', [['severity' => 'error', 'message' => 'Previous committed result']]);
        ($this->processor())(new ImportMessage($id));
        $status = $this->jobs->status($id, 1);
        self::assertSame(2, $status['processed']);
        self::assertSame(1, $status['created']);
        self::assertSame(1, $this->products->paginate([])['total']);
    }

    public function testInvalidWorkbookProducesFailedJob(): void
    {
        $file = $this->temp . '/broken.xlsx';
        file_put_contents($file, 'not a zip');
        $id = $this->jobs->create(1, 'broken.xlsx', $file);
        ($this->processor())(new ImportMessage($id));
        self::assertSame('failed', $this->jobs->status($id, 1)['status']);
    }

    public function testMessageForClearedHistoryIsAcknowledgedWithoutReimport(): void
    {
        $id = $this->jobs->create(1, 'old.xlsx', '/outside/old.xlsx');
        $this->jobs->finish($id);
        (new \App\Runtime($this->database))->catalogReset->clear(1);
        ($this->processor())(new ImportMessage($id));
        self::assertSame([], $this->jobs->recent(1));
        self::assertSame(0, $this->products->paginate([])['total']);
    }

    public function testExportCanBeReadBackWithSameCodesPricesAndAttributes(): void
    {
        $id = $this->jobs->create(1, 'products.xlsx', $this->example());
        ($this->processor())(new ImportMessage($id));
        $export = (new ExportService($this->products))->generate();
        $reader = new XlsxReader();
        try {
            self::assertSame(40, $reader->open($export));
            $rows = iterator_to_array($reader->rows());
            $first = $reader->map($rows[2]);
            self::assertSame('3UHfAid1jaMiwgBuNvnsf3', $first->externalCode);
            self::assertSame('1320.00', $first->price);
            self::assertSame('46(M)', $first->attributes['Размер']);
            self::assertCount(3, $first->imageUrls);
        } finally {
            $reader->close();
            unlink($export);
        }
    }

    /** @param list<list<string>> $rows */
    private function writeFile(array $rows): string
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена'], ...$rows]);
        $file = $this->temp . '/' . bin2hex(random_bytes(4)) . '.xlsx';
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        return $file;
    }
}
