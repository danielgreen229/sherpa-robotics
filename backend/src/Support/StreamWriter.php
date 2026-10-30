<?php

declare(strict_types=1);

namespace App\Support;

final class StreamWriter
{
    /** @param resource $stream */
    public static function write($stream, string $value): void
    {
        $offset = 0;
        $length = strlen($value);
        while ($offset < $length) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Cannot write temporary output');
            }
            $offset += $written;
        }
    }
}
