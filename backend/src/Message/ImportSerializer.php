<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class ImportSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $data = json_decode($encodedEnvelope['body'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['job_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $data['job_id']) || !is_int($data['attempt'] ?? 0) || ($data['attempt'] ?? 0) < 0) {
                throw new \UnexpectedValueException('Invalid import message');
            }
            return new Envelope(new ImportMessage($data['job_id']), [new RedeliveryStamp($data['attempt'] ?? 0)]);
        } catch (\JsonException|\UnexpectedValueException $e) {
            // AmqpReceiver rejects this message before propagating the decoding failure.
            throw new MessageDecodingFailedException('Invalid import message', 0, $e);
        }
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof ImportMessage) {
            throw new \UnexpectedValueException('Only import messages can be queued');
        }
        return ['body' => json_encode(['job_id' => $message->jobId, 'attempt' => $envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0], JSON_THROW_ON_ERROR), 'headers' => ['Content-Type' => 'application/json']];
    }
}
