<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Throwable;

/**
 * Collects info about scheduled tasks.
 */
class ScheduledTaskCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'scheduled-task-collector';
    }

    public static function registerEventListeners(Container $app): void
    {
       $app->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
           $collector = Container::getInstance()->make(static::class);
            $transaction_name = $collector->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $collector->getTransaction($transaction_name);
                if (!$transaction) {
                    $transaction = $collector->startTransaction($transaction_name);
                    $collector->addMetadata($transaction);
                }
            }
        });

       $app->events->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
           $collector = Container::getInstance()->make(static::class);
            $transaction_name = $collector->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $collector->getTransaction($transaction_name);
                if ($transaction) {
                    $collector->stopTransaction($transaction_name, $event->task->exitCode);
                }
            }
        });

       $app->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
           $collector = Container::getInstance()->make(static::class);
            $transaction_name = $collector->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $collector->getTransaction($transaction_name);
                if ($transaction) {
                    $collector->stopTransaction($transaction_name, $event->task->exitCode);
                    $collector->send($event);
                }
            }
        });
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $this->event_clock->microtime()
        );
    }

    protected function stopTransaction(string $transaction_name, ?int $result): void
    {
        // Stop the transaction and measure the time
        $this->agent->stopTransaction($transaction_name, ['result' => (int) $result]);
        $this->agent->collectEvents($transaction_name);
    }

    protected function send($event): void
    {
        try {
            $this->agent->send();
        } catch (ClientException $exception) {
            Log::error($exception, ['api_response' => (string) $exception->getResponse()->getBody()]);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    /**
     * Return no name if we shouldn't record this transaction.
     *
     * @param ScheduledTaskStarting|ScheduledTaskSkipped|ScheduledTaskFinished $event
     */
    protected function getTransactionName($event): string
    {
        $transaction_name = $event->task->command;

        return $this->shouldIgnoreTransaction($transaction_name) ? '' : $transaction_name;
    }

    protected function addMetadata(Transaction $transaction): void
    {
        $transaction->setMeta([
            'type' => 'scheduled-task',
        ]);
        $transaction->setCustomContext([
            'ran_at' => Carbon::now()->toDateTimeString(),
            'memory' => [
                'peak' => round(memory_get_peak_usage(false) / 1024 / 1024, 2) . 'M',
                'peak_real' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'M',
            ],
        ]);
    }
}
