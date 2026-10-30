<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\ImportMessage;
use App\Repository\ImportRepository;
use App\Repository\ProductRepository;
use App\Support\ApiException;
use App\Support\CatalogLock;
use Doctrine\DBAL\Connection;

final class ImportProcessor
{
    public function __construct(private readonly Connection $db, private readonly ImportRepository $jobs, private readonly ProductService $products, private readonly ProductRepository $repository)
    {
    }

    public function __invoke(ImportMessage $message): void
    {
        (new CatalogLock($this->db))->read(function () use ($message): void {
            (new CatalogLock($this->db))->job($message->jobId, fn () => $this->process($message));
        });
    }

    private function process(ImportMessage $message): void
    {
        $id = $message->jobId;
        $reader = new XlsxReader();
        $attemptStarted = false;
        try {
            try {
                $job = $this->jobs->get($id);
            } catch (ApiException $e) {
                if ($e->status === 404) {
                    // A delayed/redelivered message may outlive cleared terminal history.
                    return;
                }
                throw $e;
            }
            if (in_array($job['status'], ['completed', 'failed'], true)) {
                return;
            }
            if (!$this->jobs->beginAttempt($id)) {
                return;
            }
            $attemptStarted = true;
            try {
                $total = $reader->open((string) $job['file_path']);
            } catch (\Throwable $e) {
                $this->jobs->finish($id, $e instanceof ApiException ? $e->getMessage() : 'Не удалось прочитать XLSX. Проверьте целостность файла.');
                return;
            }
            $this->jobs->start($id, $total);
            $processedRows = $this->jobs->processedRows($id);
            foreach ($reader->rows() as $row => $raw) {
                if (isset($processedRows[$row])) {
                    continue;
                }
                try {
                    $data = $reader->map($raw);
                } catch (ApiException $e) {
                    $this->jobs->record($id, $row, 'invalid', [['severity' => 'error', 'field' => 'row', 'message' => $e->getMessage()]]);
                    continue;
                }
                $prepared = $this->products->prepare($data);
                $this->db->transactional(function () use ($data, $prepared, $id, $row): void {
                    $result = $this->repository->save($data, $prepared->images, failedImageUrls: $prepared->failedUrls);
                    $this->jobs->record($id, $row, $result['created'] ? 'created' : 'updated', $prepared->warnings);
                });
            }
            $this->jobs->finish($id);
        } catch (\Throwable $e) {
            if ($attemptStarted) {
                $this->jobs->retryOrFail($id);
            }
            throw $e;
        } finally {
            $reader->close();
        }
    }
}
