<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\ApiException;

/** Stream worksheet XML. Large shared-string tables are indexed on disk. */
final class XlsxReader
{
    private ?\ZipArchive $zip = null;
    private string $file = '';
    private string $sheet = '';
    private int $lastRow = 1;
    /** @var array<int, string> */
    private array $headers = [];
    private ?\PDO $strings = null;
    private ?string $stringsPath = null;
    /** @var array<int, string> */
    private array $cache = [];

    public function open(string $file): int
    {
        $this->close();
        $this->zip = new \ZipArchive();
        if ($this->zip->open($file) !== true) {
            $this->zip = null;
            throw new ApiException('Некорректный XLSX.', 422);
        }
        $this->file = $file;
        $size = 0;
        for ($i = 0; $i < $this->zip->numFiles; ++$i) {
            $size += (int) ($this->zip->statIndex($i)['size'] ?? 0);
        }
        if ($this->zip->numFiles > 2000 || $size > 100 * 1024 * 1024 || $this->zip->locateName('[Content_Types].xml') === false) {
            throw new ApiException('Некорректная структура или слишком большой распакованный XLSX.', 422);
        }
        $book = $this->xml($this->entry('xl/workbook.xml'));
        $sheets = $book->xpath('//*[local-name()="sheet"]') ?: [];
        $id = isset($sheets[0]) ? (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id : '';
        foreach ($this->xml($this->entry('xl/_rels/workbook.xml.rels'))->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) {
            if ($id !== '' && (string) $rel['Id'] === $id && (string) $rel['TargetMode'] !== 'External') {
                $parts = [];
                $target = (string) $rel['Target'];
                foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target) as $part) {
                    if ($part === '..') {
                        array_pop($parts);
                    } elseif ($part !== '' && $part !== '.') {
                        $parts[] = $part;
                    }
                }
                $this->sheet = implode('/', $parts);
            }
        }
        if (!str_starts_with($this->sheet, 'xl/') || str_contains($this->sheet, '#') || $this->zip->locateName($this->sheet) === false) {
            throw new ApiException('Первый лист XLSX не найден.', 422);
        }
        $this->loadStrings();
        // Scan limits and headers without keeping cells in memory.
        $reader = $this->reader($this->sheet);
        $previous = 0;
        $column = 0;
        try {
            while ($this->read($reader)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'row') {
                    $column = 0;
                    $row = (int) ($reader->getAttribute('r') ?? ($previous + 1));
                    if ($row <= $previous || $row > 10001) {
                        throw new ApiException('Максимум 10 000 строк товаров; некорректные номера строк.', 422);
                    }
                    $previous = $this->lastRow = $row;
                    if ($row === 1) {
                        foreach ($this->values($reader->readOuterXml()) as $col => $value) {
                            $name = trim($value, " \t\n\r\0\x0B\xEF\xBB\xBF");
                            if ($name !== '') {
                                if (in_array($name, $this->headers, true)) {
                                    throw new ApiException('Повторяющийся заголовок столбца: ' . $name, 422);
                                }
                                $this->headers[$col] = $name;
                            }
                        }
                    }
                } elseif ($reader->localName === 'c') {
                    $ref = $reader->getAttribute('r');
                    $column = $ref !== null ? $this->column($ref) : $column + 1;
                    if ($column > 200) { throw new ApiException('Максимум 200 столбцов.', 422); }
                }
            }
        } finally {
            $reader->close();
        }
        foreach (['Внешний код', 'Наименование', 'Цена: Цена продажи', 'Закупочная цена'] as $required) {
            if (!in_array($required, $this->headers, true)) {
                throw new ApiException('Отсутствует обязательный столбец: ' . $required, 422);
            }
        }
        return max(0, $this->lastRow - 1);
    }

    /** @return iterable<int, array<string, mixed>> */
    public function rows(): iterable
    {
        if ($this->sheet === '') {
            throw new \LogicException('Call open() first.');
        }
        $reader = $this->reader($this->sheet);
        $next = 2;
        $previous = 0;
        try {
            while ($this->read($reader)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }
                $number = (int) ($reader->getAttribute('r') ?? ($previous + 1));
                $previous = $number;
                if ($number < 2) {
                    continue;
                }
                while ($next < $number) {
                    yield $next++ => array_fill_keys(array_values($this->headers), null);
                }
                $values = $this->values($reader->readOuterXml());
                $data = [];
                foreach ($this->headers as $col => $name) {
                    $data[$name] = $values[$col] ?? null;
                }
                yield $number => $data;
                $next = $number + 1;
            }
        } finally {
            $reader->close();
        }
    }

    /** @param array<string, mixed> $row */
    public function map(array $row): ProductData
    {
        $attributes = [];
        foreach ($row as $name => $value) {
            if (str_starts_with((string) $name, 'Доп. поле')) {
                $key = trim((string) preg_replace('/^Доп\. поле\s*:?\s*/u', '', (string) $name));
                $attributes[$key] = $value;
            }
        }
        $urls = preg_split('/\s*,\s*/u', (string) ($row['Доп. поле: Ссылки на фото'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return ProductData::fromArray(['external_code' => $row['Внешний код'] ?? '', 'name' => $row['Наименование'] ?? '', 'description' => $row['Описание'] ?? '', 'price' => $row['Цена: Цена продажи'] ?? null, 'purchase_price' => $row['Закупочная цена'] ?? null, 'attributes' => $attributes, 'image_urls' => $urls]);
    }

    private function loadStrings(): void
    {
        if ($this->zip?->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        $this->stringsPath = tempnam(sys_get_temp_dir(), 'catalog-strings-') ?: throw new \RuntimeException('Cannot create string index');
        $this->strings = new \PDO('sqlite:' . $this->stringsPath);
        $this->strings->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA cache_size=-2048; CREATE TABLE strings (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $insert = $this->strings->prepare('INSERT INTO strings VALUES (?, ?)');
        $reader = $this->reader('xl/sharedStrings.xml');
        $index = 0;
        $this->strings->beginTransaction();
        try {
            while ($this->read($reader)) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                    $insert->execute([$index++, $this->text($this->xml($reader->readOuterXml()))]);
                }
            }
            $this->strings->commit();
        } finally {
            $reader->close();
        }
    }

    private function sharedString(int $id): string
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $query = $this->strings?->prepare('SELECT value FROM strings WHERE id = ?');
        if ($query === null || $query === false) {
            throw new ApiException('Таблица строк XLSX отсутствует.', 422);
        }
        $query->execute([$id]);
        $value = $query->fetchColumn();
        if (!is_string($value)) {
            throw new ApiException('Некорректная ссылка на строку XLSX.', 422);
        }
        if (count($this->cache) >= 256) {
            $this->cache = [];
        }
        return $this->cache[$id] = $value;
    }

    private function column(string $ref): int
    {
        if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/D', $ref, $match)) {
            throw new ApiException('Некорректный адрес ячейки XLSX.', 422);
        }
        $col = 0;
        foreach (str_split($match[1]) as $char) {
            $col = $col * 26 + ord($char) - 64;
        }
        if ($col > 200) {
            throw new ApiException('Максимум 200 столбцов.', 422);
        }
        return $col;
    }

    /** @return array<int, string> */
    private function values(string $row): array
    {
        $values = [];
        $previous = 0;
        foreach ($this->xml($row)->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            if ($cell->getName() !== 'c') {
                continue;
            }
            $ref = (string) $cell['r'];
            $col = $ref !== '' ? $this->column($ref) : $previous + 1;
            if ($col > 200) {
                throw new ApiException('Максимум 200 столбцов.', 422);
            }
            $previous = $col;
            $nodes = $cell->xpath('./*[local-name()="v"]') ?: [];
            $value = isset($nodes[0]) ? (string) $nodes[0] : '';
            $values[$col] = match ((string) $cell['t']) {
                's' => preg_match('/^[0-9]+$/D', $value) ? $this->sharedString((int) $value) : throw new ApiException('Некорректный индекс строки XLSX.', 422),
                'inlineStr' => $this->text($cell),
                default => $value,
            };
        }
        return $values;
    }

    private function text(\SimpleXMLElement $xml): string
    {
        return implode('', array_map(static fn (\SimpleXMLElement $part): string => (string) $part, $xml->xpath('.//*[local-name()="t" and not(ancestor::*[local-name()="rPh"])]') ?: []));
    }

    private function entry(string $name): string
    {
        $value = $this->zip?->getFromName($name);
        return is_string($value) && strlen($value) <= 1024 * 1024 ? $value : throw new ApiException('Некорректные метаданные XLSX.', 422);
    }

    private function reader(string $entry): \XMLReader
    {
        $reader = new \XMLReader();
        if (!@$reader->open('zip://' . $this->file . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ApiException('Не удалось прочитать XML из XLSX.', 422);
        }
        $reader->setParserProperty(\XMLReader::LOADDTD, false);
        $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
        return $reader;
    }

    private function read(\XMLReader $reader): bool
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $ok = $reader->read();
            if (libxml_get_errors() || ($ok && $reader->nodeType === \XMLReader::DOC_TYPE)) {
                throw new ApiException('Некорректный XML или запрещённый DTD в XLSX.', 422);
            }
            return $ok;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function xml(string $value): \SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $value)) {
            throw new ApiException('DTD и внешние сущности запрещены.', 422);
        }
        $xml = @simplexml_load_string($value, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        return $xml !== false ? $xml : throw new ApiException('Некорректный XML в XLSX.', 422);
    }

    public function close(): void
    {
        if ($this->strings?->inTransaction()) {
            $this->strings->rollBack();
        }
        $this->strings = null;
        if ($this->stringsPath !== null && is_file($this->stringsPath)) {
            unlink($this->stringsPath);
        }
        $this->stringsPath = null;
        $this->cache = [];
        $this->zip?->close();
        $this->zip = null;
        $this->sheet = '';
        $this->headers = [];
        $this->lastRow = 1;
    }

    public function __destruct()
    {
        $this->close();
    }
}
