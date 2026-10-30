<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ImportRepository;
use App\Support\ApiException;
use App\Support\Config;
use Psr\Http\Message\UploadedFileInterface;

final class ImportService
{
    public function __construct(private readonly ImportRepository $jobs, private readonly ImportQueue $queue)
    {
    }

    public function upload(UploadedFileInterface $file, int $user): string
    {
        $filename = basename($file->getClientFilename() ?? '');
        if ($file->getError() !== UPLOAD_ERR_OK || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new ApiException('Загрузите корректный файл с расширением .xlsx.', 422);
        }
        $max = (int) Config::env('MAX_UPLOAD_MB', '10') * 1024 * 1024;
        if ($file->getSize() === null || $file->getSize() <= 0 || $file->getSize() > $max || strlen($filename) > 255) {
            throw new ApiException('Файл пуст, превышает допустимый размер или имеет слишком длинное имя.', 422);
        }
        $directory = Config::storage() . '/uploads';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $file->moveTo($path);
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true)) {
                throw new ApiException('MIME-тип файла не соответствует XLSX.', 422);
            }
            // Check the ZIP container here; workbook parsing stays asynchronous.
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new ApiException('Некорректный XLSX-контейнер.', 422);
            }
            $valid = $zip->locateName('xl/workbook.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
            $zip->close();
            if (!$valid) {
                throw new ApiException('Файл не содержит книгу Excel.', 422);
            }
            $id = $this->jobs->create($user, $filename, $path);
        } catch (\Throwable $e) {
            unlink($path);
            throw $e;
        }
        try {
            $this->queue->dispatch($id);
            $this->jobs->published($id);
        } catch (\Throwable $e) {
            // Durable outbox: queued jobs are republished by the worker sweep.
            error_log('Queue publish deferred: ' . $e->getMessage());
        }
        return $id;
    }
}
