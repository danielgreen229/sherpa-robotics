<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\ImportMessage;
use App\Message\ImportSerializer;
use App\Support\Config;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class ImportQueue
{
    private ?TransportInterface $transport = null;

    public function transport(): TransportInterface
    {
        return $this->transport ??= (new AmqpTransportFactory())->createTransport(Config::env('MESSENGER_TRANSPORT_DSN', 'amqp://catalog:catalog@rabbitmq:5672/%2f/catalog_imports'), ['auto_setup' => true, 'exchange' => ['name' => 'catalog_imports'], 'queues' => ['catalog_imports' => []], 'heartbeat' => 0, 'connect_timeout' => 3], new ImportSerializer());
    }

    public function dispatch(string $job): void
    {
        $this->transport()->send(new Envelope(new ImportMessage($job)));
    }
}
