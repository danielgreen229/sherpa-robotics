<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\ApiException;
use App\Support\StreamWriter;

/** Deduplicate XLSX strings on disk, keeping both memory and worksheet XML bounded. */
final class ExportStringTable
{
    private ?\PDO $db;
    private string $path;
    private int $count = 0;
    private int $bytes = 0;
    /** @var array<string, int> */
    private array $cache = [];

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'catalog-export-strings-') ?: throw new \RuntimeException('Cannot create export strings');
        $this->db = new \PDO('sqlite:' . $this->path);
        $this->db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA cache_size=-2048; CREATE TABLE strings (id INTEGER PRIMARY KEY, value TEXT NOT NULL UNIQUE)');
        $this->db->beginTransaction();
    }

    public function id(string $value): int
    {
        if (isset($this->cache[$value])) {
            return $this->cache[$value];
        }
        $db = $this->db ?? throw new \LogicException('String table closed');
        $query = $db->prepare('SELECT id FROM strings WHERE value = ?');
        $query->execute([$value]);
        $id = $query->fetchColumn();
        if ($id === false) {
            $this->bytes += strlen(htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')) + 48;
            if ($this->bytes > 100 * 1024 * 1024) {
                throw new ApiException('Распакованный экспорт превышает 100 МБ. Уточните фильтры.', 422);
            }
            $id = $this->count++;
            $db->prepare('INSERT INTO strings (id, value) VALUES (?, ?)')->execute([$id, $value]);
        }
        if (count($this->cache) >= 256) {
            $this->cache = [];
        }
        return $this->cache[$value] = (int) $id;
    }

    /** @param resource $stream */
    public function write($stream): void
    {
        $db = $this->db ?? throw new \LogicException('String table closed');
        StreamWriter::write($stream, '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" uniqueCount="' . $this->count . '">');
        $query = $db->query('SELECT value FROM strings ORDER BY id') ?: throw new \RuntimeException('Cannot read export strings');
        while (($value = $query->fetchColumn()) !== false) {
            StreamWriter::write($stream, '<si><t xml:space="preserve">' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>');
        }
        StreamWriter::write($stream, '</sst>');
    }

    public function close(): void
    {
        if ($this->db?->inTransaction()) {
            $this->db->rollBack();
        }
        $this->db = null;
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
