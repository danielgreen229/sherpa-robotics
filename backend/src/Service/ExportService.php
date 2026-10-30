<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Support\ApiException;
use App\Support\StreamWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Stream a standard XLSX using an on-disk shared-string dictionary. */
final class ExportService
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters = []): string
    {
        return $this->products->snapshot(fn (): string => $this->writeWorkbook($filters));
    }

    /** @param array<string, mixed> $filters */
    private function writeWorkbook(array $filters): string
    {
        $keys = [];
        $count = 0;
        foreach ($this->products->iterate($filters) as $product) {
            if (++$count > 10000) {
                throw new ApiException('Экспорт ограничен 10 000 товарами. Уточните фильтры.', 422);
            }
            foreach ($product->attributes as $attribute) {
                $keys[$attribute->key] = true;
            }
            if (!$product->images->isEmpty()) {
                $keys['Ссылки на фото'] = true;
            }
            if (count($keys) > 195) {
                throw new ApiException('Экспорт ограничен 200 столбцами. Уточните фильтры.', 422);
            }
        }
        $attributes = array_map(static fn (int|string $key): string => (string) $key, array_keys($keys));
        $headers = ['Внешний код', 'Наименование', 'Описание', 'Цена: Цена продажи', 'Закупочная цена', ...array_map(static fn (string $key): string => 'Доп. поле: ' . $key, $attributes)];
        $strings = new ExportStringTable();
        $shared = tmpfile();
        $sheet = tmpfile();
        $path = tempnam(sys_get_temp_dir(), 'catalog-export-');
        if ($sheet === false || $shared === false || $path === false) {
            if (is_resource($sheet)) {
                fclose($sheet);
            }
            if ($path !== false) {
                unlink($path);
            }
            if (is_resource($shared)) { fclose($shared); }
            $strings->close();
            throw new \RuntimeException('Cannot create export');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            StreamWriter::write($sheet, '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
            $this->row($sheet, 1, $headers, $strings);
            $index = 2;
            foreach ($this->products->iterate($filters) as $product) {
                if ($index > 10001) {
                    throw new ApiException('Каталог изменился во время экспорта. Повторите с фильтрами.', 409);
                }
                $values = [];
                foreach ($product->attributes as $attribute) {
                    $values[$attribute->key] = $attribute->value;
                    if (!isset($keys[$attribute->key])) {
                        throw new ApiException('Характеристики изменились во время экспорта. Повторите запрос.', 409);
                    }
                }
                $urls = array_map(static fn ($image): string => $image->url, $product->images->toArray());
                $values['Ссылки на фото'] = implode(', ', $urls) ?: ($values['Ссылки на фото'] ?? '');
                $row = [$product->externalCode, $product->name, $product->description, $product->price, $product->purchasePrice];
                foreach ($attributes as $key) {
                    $row[] = $values[$key] ?? '';
                }
                $this->row($sheet, $index++, $row, $strings);
            }
            StreamWriter::write($sheet, '</sheetData></worksheet>');
            $strings->write($shared);
            if (ftell($sheet) + ftell($shared) + 4096 > 100 * 1024 * 1024) {
                throw new ApiException('Распакованный экспорт превышает 100 МБ. Уточните фильтры.', 422);
            }
            fflush($sheet);
            fflush($shared);
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open export archive');
            }
            $opened = true;
            $entries = [
                '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>',
                '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Товары" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            ];
            foreach ($entries as $name => $body) {
                if (!$zip->addFromString($name, $body)) {
                    throw new \RuntimeException('Cannot write export metadata');
                }
            }
            if (!$zip->addFile(stream_get_meta_data($sheet)['uri'] ?? throw new \RuntimeException('Worksheet path unavailable'), 'xl/worksheets/sheet1.xml') || !$zip->addFile(stream_get_meta_data($shared)['uri'] ?? throw new \RuntimeException('String table path unavailable'), 'xl/sharedStrings.xml') || !$zip->close()) {
                throw new \RuntimeException('Cannot finish export archive');
            }
            $opened = false;
            if (filesize($path) > 10 * 1024 * 1024) {
                throw new ApiException('Экспорт превышает 10 МБ. Уточните фильтры для обратного импорта.', 422);
            }
            return $path;
        } catch (\Throwable $e) {
            // Close before removing the archive; tmpfile is kept alive until ZIP finishes.
            if ($opened) {
                try { $zip->close(); } catch (\Throwable) {}
            }
            unlink($path);
            throw $e;
        } finally {
            fclose($sheet);
            fclose($shared);
            $strings->close();
        }
    }

    /** @param resource $stream
     *  @param list<string> $values
     */
    private function row($stream, int $number, array $values, ExportStringTable $strings): void
    {
        StreamWriter::write($stream, '<row r="' . $number . '">');
        foreach ($values as $col => $value) {
            $ref = Coordinate::stringFromColumnIndex($col + 1) . $number;
            // Shared strings preserve codes and never execute spreadsheet formulas.
            $id = $strings->id($value);
            StreamWriter::write($stream, '<c r="' . $ref . '" t="s"><v>' . $id . '</v></c>');
        }
        StreamWriter::write($stream, '</row>');
        if (ftell($stream) > 100 * 1024 * 1024) {
            throw new ApiException('Распакованный экспорт превышает 100 МБ. Уточните фильтры.', 422);
        }
    }
}
