<?php

declare(strict_types=1);

namespace Tests;

use App\Message\ImportMessage;
use App\Message\ImportSerializer;
use App\Service\ExportService;
use App\Service\ImageFetcher;
use App\Service\ImportProcessor;
use App\Service\ImportReportService;
use App\Service\ProductData;
use App\Service\ProductService;
use App\Service\XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class ReliabilityTest extends DatabaseTestCase
{
    private function processor(): ImportProcessor
    {
        return new ImportProcessor($this->database->connection(), $this->jobs, new ProductService($this->products, new class implements ImageFetcher {
            public function fetch(string $url): array { throw new \RuntimeException('Temporary host failure'); }
        }), $this->products);
    }

    /** @param list<list<string>> $rows */
    private function workbook(array $rows): string
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows);
        $file = $this->temp . '/' . bin2hex(random_bytes(4)) . '.xlsx';
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        return $file;
    }

    public function testNumericAttributeKeysImportAndExportWithoutStoppingFollowingRows(): void
    {
        $file = $this->workbook([
            ['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена', 'Доп. поле: 123'],
            ['0001', 'Первый', '100', '50', 'Значение'], ['0002', 'Второй', '200', '50', 'Ещё'],
        ]);
        $id = $this->jobs->create(1, 'numeric.xlsx', $file);
        ($this->processor())(new ImportMessage($id));
        self::assertSame(2, $this->jobs->status($id, 1)['created']);
        self::assertSame([], $this->jobs->status($id, 1)['errors']);
        $product = $this->products->find((int) $this->products->paginate([])['items'][0]['id'])->toArray();
        self::assertSame('123', $product['attributes'][0]['key']);
        $export = (new ExportService($this->products))->generate();
        try {
            // Read with the independent, established XLSX library as well as our reader.
            $book = IOFactory::load($export);
            self::assertSame('0001', (string) $book->getActiveSheet()->getCell('A2')->getValue());
            self::assertContains('Доп. поле: 123', array_map('strval', $book->getActiveSheet()->rangeToArray('A1:G1')[0]));
            $book->disconnectWorksheets();
            $reader = new XlsxReader();
            self::assertSame(2, $reader->open($export));
            $first = $reader->map(iterator_to_array($reader->rows())[2]);
            self::assertSame('Значение', $first->attributes[123]);
            $reader->close();
        } finally { unlink($export); }
    }

    public function testFailedReplacementKeepsWholeGalleryAndExplicitEmptyUrlsRemoveIt(): void
    {
        $input = ['external_code' => 'photo', 'name' => 'Photo', 'price' => '100', 'purchase_price' => '50'];
        $images = [];
        foreach (['a', 'b'] as $name) {
            $path = '/media/' . $name . '.jpg'; file_put_contents($this->temp . $path, 'fixture');
            $images[] = ['url' => 'https://example.com/' . $name . '.jpg', 'path' => $path];
        }
        $this->products->save(ProductData::fromArray($input), $images);
        $service = new ProductService($this->products, new class implements ImageFetcher {
            public function fetch(string $url): array { throw new \RuntimeException('Temporary outage'); }
        });
        $result = $service->save(ProductData::fromArray($input + ['image_urls' => ['https://example.com/new.jpg']]));
        self::assertCount(2, $result['product']['images']);
        self::assertCount(1, $result['warnings']);
        self::assertSame([], $service->save(ProductData::fromArray($input))['product']['images']);
    }

    public function testRetryBudgetIsPersistentAndDoesNotResetOnFreshMessages(): void
    {
        $db = $this->database->connection();
        $db->executeStatement("CREATE TRIGGER fail_product BEFORE INSERT ON products BEGIN SELECT RAISE(ABORT, 'simulated database failure'); END");
        $file = $this->workbook([['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена'], ['retry', 'Retry', '100', '50']]);
        $id = $this->jobs->create(1, 'retry.xlsx', $file);
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            try { ($this->processor())(new ImportMessage($id)); self::fail('DB failure expected'); } catch (\Doctrine\DBAL\Exception) {}
            $job = $this->jobs->get($id);
            self::assertSame($attempt, (int) $job['attempts']);
            self::assertSame($attempt === 4 ? 'failed' : 'queued', $job['status']);
            self::assertSame(0, $this->products->paginate([])['total']);
            if ($attempt < 4) {
                self::assertNotContains($id, $this->jobs->pending());
                ($this->processor())(new ImportMessage($id));
                self::assertSame($attempt, (int) $this->jobs->get($id)['attempts']);
                $db->update('import_jobs', ['next_attempt_at' => 0], ['id' => $id]);
                self::assertContains($id, $this->jobs->pending());
            }
        }
        ($this->processor())(new ImportMessage($id));
        self::assertSame(4, (int) $this->jobs->get($id)['attempts']);
        self::assertNotContains($id, $this->jobs->pending());
    }

    public function testRecoveryDoesNotFloodRecentlyPublishedJobs(): void
    {
        $id = $this->jobs->create(1, 'pending.xlsx', 'unused');
        self::assertContains($id, $this->jobs->pending());
        $this->jobs->published($id);
        self::assertNotContains($id, $this->jobs->pending());
        $this->database->connection()->update('import_jobs', ['last_published_at' => time() - 61], ['id' => $id]);
        self::assertContains($id, $this->jobs->pending());
    }

    public function testPartialPhotoFailureKeepsExistingGalleryUntilCompleteReplacement(): void
    {
        $input = ['external_code' => 'partial', 'name' => 'Partial', 'price' => '100', 'purchase_price' => '50'];
        file_put_contents($this->temp . '/media/old.jpg', 'fixture');
        $old = [['url' => 'https://example.com/old.jpg', 'path' => '/media/old.jpg']];
        $this->products->save(ProductData::fromArray($input), $old);
        $service = new ProductService($this->products, new class implements ImageFetcher {
            public function fetch(string $url): array
            {
                if (str_contains($url, 'fail')) { throw new \RuntimeException('Temporary failure'); }
                return ['url' => $url, 'path' => '/media/new.jpg'];
            }
        });
        $result = $service->save(ProductData::fromArray($input + ['image_urls' => ['https://example.com/new.jpg', 'https://example.com/fail.jpg']]));
        self::assertSame($old, $result['product']['images']);
        self::assertCount(1, $result['warnings']);
        $result = $service->save(ProductData::fromArray($input + ['image_urls' => ['https://example.com/new.jpg']]));
        self::assertSame('/media/new.jpg', $result['product']['images'][0]['path']);
    }

    public function testStatusIsBoundedAndFullReportKeepsAllIssues(): void
    {
        $id = $this->jobs->create(1, 'report.xlsx', 'unused');
        for ($row = 2; $row <= 126; ++$row) {
            $this->jobs->record($id, $row, 'invalid', [['severity' => 'error', 'message' => 'A'], ['severity' => 'warning', 'message' => 'B']]);
        }
        $status = $this->jobs->status($id, 1);
        self::assertSame(250, $status['error_count']);
        self::assertCount(100, $status['errors']);
        $last = $this->jobs->errors($id, 1, ['page' => 3, 'limit' => 100]);
        self::assertCount(50, $last['items']);
        self::assertSame(102, $last['items'][0]['row']);
        $stream = (new ImportReportService($this->jobs))->generate($id, 1);
        $report = json_decode(stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR); fclose($stream);
        self::assertCount(250, $report['errors']);
        self::assertSame(126, $report['errors'][249]['row']);
        $this->expectException(\App\Support\ApiException::class);
        $this->jobs->errors($id, 2, []);
    }

    public function testMalformedJsonUsesMessengerDecodingFailureContract(): void
    {
        foreach (['{broken', '{"job_id":"bad"}', '{"job_id":"' . str_repeat('a', 32) . '","attempt":-1}'] as $body) {
            try { (new ImportSerializer())->decode(['body' => $body, 'headers' => []]); self::fail('Invalid payload accepted'); }
            catch (MessageDecodingFailedException $e) { self::assertNotNull($e->getPrevious()); }
        }
    }

    public function testStreamedReaderSupportsInlineStringsRichTextSparseRowsAndFirstSheetOnly(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена']]);
        $sheet->setCellValue('A3', '00123');
        $rich = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
        $rich->createText('Rich '); $rich->createTextRun('text');
        $sheet->setCellValue('B3', $rich);
        $sheet->setCellValue('C3', '100'); $sheet->setCellValue('D3', '50');
        $book->createSheet()->setCellValue('ZZ20000', 'ignored sheet');
        $file = $this->temp . '/sparse.xlsx'; (new Xlsx($book))->save($file); $book->disconnectWorksheets();
        $reader = new XlsxReader();
        self::assertSame(2, $reader->open($file));
        $rows = iterator_to_array($reader->rows());
        self::assertNull($rows[2]['Внешний код']);
        self::assertSame('00123', $rows[3]['Внешний код']);
        self::assertSame('Rich text', $rows[3]['Наименование']);
        $reader->close();
    }

    public function testStreamedReaderRejectsDtdAndCellsOutsideColumnLimit(): void
    {
        foreach (['<!DOCTYPE worksheet [<!ENTITY x "unsafe">]><worksheet/>', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="GS1"><v>1</v></c></row></sheetData></worksheet>'] as $xml) {
            $file = $this->workbook([['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена']]);
            $zip = new \ZipArchive(); $zip->open($file); $zip->addFromString('xl/worksheets/sheet1.xml', $xml); $zip->close();
            $reader = new XlsxReader();
            try { $reader->open($file); self::fail('Unsafe workbook accepted'); }
            catch (\App\Support\ApiException $e) { self::assertSame(422, $e->status); }
            finally { $reader->close(); }
        }
    }

    public function testXmlControlCharactersAreRejectedAsRowData(): void
    {
        $this->expectException(\App\Support\ApiException::class);
        ProductData::fromArray(['external_code' => 'bad', 'name' => "Bad\x01", 'price' => '100', 'purchase_price' => '50']);
    }
}
