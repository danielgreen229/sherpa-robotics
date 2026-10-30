<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ImportRepository;
use App\Support\StreamWriter;

final class ImportReportService
{
    public function __construct(private readonly ImportRepository $jobs)
    {
    }

    /** @return resource */
    public function generate(string $id, int $user)
    {
        return $this->jobs->snapshot(fn () => $this->writeReport($id, $user));
    }

    /** @return resource */
    private function writeReport(string $id, int $user)
    {
        $status = $this->jobs->status($id, $user);
        $stream = tmpfile();
        if ($stream === false) {
            throw new \RuntimeException('Cannot create report');
        }
        try {
            unset($status['errors']);
            StreamWriter::write($stream, substr(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 0, -1) . ',"errors":[');
            $separator = '';
            foreach ($this->jobs->iterateErrors($id) as $error) {
                StreamWriter::write($stream, $separator . json_encode($error, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $separator = ',';
            }
            StreamWriter::write($stream, ']}');
            rewind($stream);
            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

}
