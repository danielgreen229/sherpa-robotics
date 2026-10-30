<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ApiException;
use App\Support\CatalogLock;
use Doctrine\DBAL\Connection;

final class ImportRepository
{
    public const MAX_ATTEMPTS = 4;
    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function snapshot(callable $operation): mixed
    {
        return \App\Support\ReadSnapshot::run($this->db, $operation);
    }

    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $user, string $filename, string $path): string
    {
        return (new CatalogLock($this->db))->read(fn (): string => $this->db->transactional(function () use ($user, $filename, $path): string {
            $this->lockOwner($user);
            $id = bin2hex(random_bytes(16));
            $this->db->insert('import_jobs', ['id' => $id, 'user_id' => $user, 'filename' => $filename, 'file_path' => $path]);
            return $id;
        }));
    }

    private function lockOwner(int $user): void
    {
        if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $this->db->executeQuery('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
        }
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?int $user = null): array
    {
        $job = $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        if (!$job || ($user !== null && (int) $job['user_id'] !== $user)) {
            throw new ApiException('Задача импорта не найдена.', 404);
        }
        return $job;
    }

    /** @return array<string, mixed> */
    public function status(string $id, int $user): array
    {
        $job = $this->get($id, $user);
        unset($job['file_path'], $job['user_id']);
        $outcomes = $this->db->fetchAllAssociative('SELECT outcome, COUNT(*) AS amount FROM import_rows WHERE job_id = ? GROUP BY outcome', [$id]);
        $job['created'] = $job['updated'] = $job['invalid'] = $job['processed'] = 0;
        foreach ($outcomes as $row) {
            $job[$row['outcome']] = (int) $row['amount'];
            $job['processed'] += (int) $row['amount'];
        }
        $job['total'] = (int) $job['total'];
        $job['progress'] = $job['total'] > 0 ? min(100, (int) floor($job['processed'] * 100 / $job['total'])) : ($job['status'] === 'completed' ? 100 : 0);
        $job['attempts'] = (int) $job['attempts'];
        $job['error_count'] = $this->errorCount($id);
        $job['errors'] = iterator_to_array($this->iterateErrors($id, 100), false);
        return $job;
    }

    private function errorCount(string $id): int
    {
        $length = $this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform ? 'jsonb_array_length(errors)' : 'json_array_length(errors)';
        return (int) $this->db->fetchOne('SELECT COALESCE(SUM(' . $length . '), 0) FROM import_rows WHERE job_id = ?', [$id]);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateErrors(string $id, ?int $limit = null, int $offset = 0): \Generator
    {
        // Read one bounded receipt at a time; never hydrate the complete report in PHP.
        $after = 0;
        $position = 0;
        $returned = 0;
        do {
            $rows = $this->db->fetchAllAssociative("SELECT row_number, errors FROM import_rows WHERE job_id = ? AND row_number > ? AND CAST(errors AS TEXT) <> '[]' ORDER BY row_number LIMIT 100", [$id, $after]);
            foreach ($rows as $row) {
                $after = (int) $row['row_number'];
                foreach (json_decode((string) $row['errors'], true, flags: JSON_THROW_ON_ERROR) as $error) {
                    if ($position++ < $offset) {
                        continue;
                    }
                    if ($limit !== null && $returned >= $limit) {
                        return;
                    }
                    ++$returned;
                    yield ['row' => $after] + $error;
                }
            }
        } while (count($rows) === 100);
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    public function errors(string $id, int $user, array $query): array
    {
        $this->get($id, $user);
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 1000000 || $limit === false || $limit < 1 || $limit > 100) {
            throw new ApiException('Некорректные параметры отчёта.', 422);
        }
        $total = $this->errorCount($id);
        return ['items' => iterator_to_array($this->iterateErrors($id, $limit, ($page - 1) * $limit), false), 'total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int) ceil($total / $limit)];
    }

    /** Called only while the per-job advisory lock is held. */
    public function beginAttempt(string $id): bool
    {
        $job = $this->get($id);
        if ((int) $job['next_attempt_at'] > time()) {
            return false;
        }
        if ((int) $job['attempts'] >= self::MAX_ATTEMPTS) {
            $this->finish($id, 'Не удалось завершить импорт после повторных попыток. Проверьте журналы worker.');
            return false;
        }
        $this->db->executeStatement('UPDATE import_jobs SET attempts = attempts + 1 WHERE id = ?', [$id]);
        return true;
    }

    public function retryOrFail(string $id): void
    {
        $job = $this->get($id);
        if ((int) $job['attempts'] >= self::MAX_ATTEMPTS) {
            $this->finish($id, 'Не удалось завершить импорт после повторных попыток. Проверьте журналы worker.');
            return;
        }
        $this->db->update('import_jobs', ['status' => 'queued', 'last_published_at' => 0, 'next_attempt_at' => time() + 2 ** (int) $job['attempts'], 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function start(string $id, int $total): void
    {
        $this->db->update('import_jobs', ['status' => 'processing', 'total' => $total, 'failure' => null, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function finish(string $id, ?string $failure = null): void
    {
        $this->db->update('import_jobs', ['status' => $failure === null ? 'completed' : 'failed', 'failure' => $failure, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    /** @return array<int, true> */
    public function processedRows(string $job): array
    {
        $rows = $this->db->fetchFirstColumn('SELECT row_number FROM import_rows WHERE job_id = ?', [$job]);
        return array_fill_keys(array_map('intval', $rows), true);
    }

    /** @param list<array<string, string>> $errors */
    public function record(string $job, int $row, string $outcome, array $errors): void
    {
        $this->db->insert('import_rows', ['job_id' => $job, 'row_number' => $row, 'outcome' => $outcome, 'errors' => json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $this->db->update('import_jobs', ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $job]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $user): array
    {
        return $this->db->fetchAllAssociative('SELECT id, filename, status, created_at FROM import_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$user]);
    }

    public function published(string $id): void
    {
        $this->db->update('import_jobs', ['last_published_at' => time()], ['id' => $id]);
    }

    /** @return list<string> */
    public function pending(): array
    {
        return $this->db->fetchFirstColumn("SELECT id FROM import_jobs WHERE next_attempt_at <= ? AND last_published_at < ? AND (status = 'queued' OR (status = 'processing' AND updated_at < ?)) ORDER BY created_at LIMIT 100", [time(), time() - 60, gmdate('Y-m-d H:i:s', time() - 60)]);
    }
}
