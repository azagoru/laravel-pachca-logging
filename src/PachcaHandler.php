<?php

namespace SavenkovDev\PachcaLogger;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class PachcaHandler extends AbstractProcessingHandler
{
    private string $webhook;
    private string $name;
    private Client $guzzle;

    public function __construct($webhook, $name, $level = 'debug', $bubble = true)
    {
        $this->name = $name;
        $this->webhook = $webhook;
        $this->guzzle = new Client();
        parent::__construct($level ,$bubble);
    }

    protected function getStacktrace(array|LogRecord $record): ?string
    {
        if (!is_subclass_of($record['context']['exception'] ?? '', \Throwable::class))
        {
            return null;
        }
        /** @var \Throwable $exception */
        $exception = $record['context']['exception'];

        return "On {$exception->getFile()}:{$exception->getLine()} (code {$exception->getCode()})\n" .
            "Stacktrace:\n" .
            $exception->getTraceAsString();
    }

    protected function write(array|LogRecord $record): void
    {
        $stacktrace = $this->getStacktrace($record);
        $header = ($record['level'] >= 400 ? "💥 " : "ℹ️ ") . $record['level'] . " from " . $this->name;
        $message = $header . PHP_EOL . PHP_EOL;
        if ($record['message']) {
            $message .= $record['message'] . PHP_EOL .  PHP_EOL;
        }
        if (!empty($stacktrace)) {
            $stacktrace = str_replace('->', '→', $stacktrace);
            $message .= $stacktrace  . PHP_EOL .  PHP_EOL;
        }
        $log = [
            'message' =>  $message . '---------------------------------------------------------------------------------------------',
        ];
        $this->guzzle->request('POST', $this->webhook, [RequestOptions::JSON => $log]);
    }
}
