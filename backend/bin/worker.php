<?php

declare(strict_types=1);

use App\Message\ImportMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

/** @var App\Runtime $runtime */
$runtime = require dirname(__DIR__) . '/bootstrap.php';
$bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([ImportMessage::class => [$runtime->processor()]]))]);
$state = new class { public bool $running = true; };
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($state): void { $state->running = false; });
    pcntl_signal(SIGINT, static function () use ($state): void { $state->running = false; });
}
$lastSweep = 0;
$handled = 0;
$started = time();
while ($state->running && $handled < 100 && time() - $started < 3600) {
    try {
        $transport = $runtime->queue->transport();
        if (time() - $lastSweep >= 5) {
            // Recover pending jobs after a publish failure or broker restart.
            foreach ($runtime->jobs->pending() as $id) {
                $runtime->queue->dispatch($id);
                $runtime->jobs->published($id);
            }
            $lastSweep = time();
        }
        $received = false;
        foreach ($transport->get() as $envelope) {
            $received = true;
            $message = $envelope->getMessage();
            try {
                $bus->dispatch(new Envelope($message));
                $transport->ack($envelope);
                ++$handled;
            } catch (Throwable $e) {
                error_log((string) $e);
                // The processor persists the job attempt/backoff before throwing.
                // Recovery republishes due jobs; message duplication cannot reset the budget.
                $transport->reject($envelope);
            }
        }
        if (!$received) {
            usleep(250000);
        }
    } catch (MessageDecodingFailedException $e) {
        error_log('Rejected malformed import message: ' . $e->getMessage());
        ++$handled;
    } catch (Throwable $e) {
        error_log('Worker connection error: ' . $e->getMessage());
        // Exit so the process supervisor / Docker restart policy reconnects cleanly.
        exit(1);
    }
}
