<?php

namespace SavenkovDev\PachcaLogger;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class PachcaHandler extends AbstractProcessingHandler
{
    private string $webhook;
    private string $name;
    private Client $guzzle;

    public function __construct($webhook, $name, $level = Level::Debug, $bubble = true)
    {
        $this->name = $name;
        $this->webhook = $webhook;
        $this->guzzle = new Client();
        parent::__construct($level ,$bubble);
    }

    protected function getStacktrace(LogRecord $record): ?string
    {
        if (!is_subclass_of($record->context['exception'] ?? '', \Throwable::class))
        {
            return null;
        }
        /** @var \Throwable $exception */
        $exception = $record->context['exception'];

        return "On {$exception->getFile()}:{$exception->getLine()} (code {$exception->getCode()})\n" .
            "Stacktrace:\n" .
            $exception->getTraceAsString();
    }

    protected function write(LogRecord $record): void
    {
        $formatter = new LineFormatter(null, null, true, true, true);
        $content = $formatter->format($record);
        $stacktrace = str_replace('->', '→', $this->getStacktrace($record));
        $header = ($record->level >= Level::Error ? "💥 " : "ℹ️ ") . $record->level->getName() . " from " . $this->name;
        $message = $header . PHP_EOL . PHP_EOL;
        if ($record->message) {
            $message .= $record->message . PHP_EOL .  PHP_EOL;
        }
        if (!empty($stacktrace)) {
            $message .= $stacktrace  . PHP_EOL .  PHP_EOL;
        }
        $log = [
            'message' =>  $message . '---------------------------------------------------------------------------------------------',
        ];
        $this->guzzle->request('POST', $this->webhook, [RequestOptions::JSON => $log]);
    }
}
