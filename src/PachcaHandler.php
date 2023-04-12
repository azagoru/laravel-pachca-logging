<?php

namespace Azagoru\PachcaLogging;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use stdClass;
use Throwable;

class PachcaHandler extends AbstractProcessingHandler
{
    private string $webhook;
    private string $name;

    private int $maxDepth;
    private bool $withTrace;
    private bool $withTraceMarkup;
    private bool $withTraceVendorLines;

    private Client $guzzle;

    public function __construct(
        $webhook, 
        $name, 
        $level = 'debug', 
        $bubble = true, 
        $maxDepth = 2,
        $withTrace = true,
        $withTraceMarkup = true,
        $withTraceVendorLines = true,
    )
    {
        $this->maxDepth = $maxDepth;
        $this->name = $name;
        $this->webhook = $webhook;
        $this->guzzle = new Client();
        $this->withTrace = $withTrace;
        $this->withTraceMarkup = $withTraceMarkup;
        $this->withTraceVendorLines = $withTraceVendorLines;

        parent::__construct($level, $bubble);
    }

    /**
     * @throws GuzzleException
     */
    protected function write(array|LogRecord $record): void
    {
        $header     = $this->getHeader($record);
        $context    = $this->getContext($record);
        $stacktrace = $this->getStacktrace($record);

        $message = $header;
        if ($record['message']) {
            $message .= $record['message'] . PHP_EOL .  PHP_EOL;
        }
        if (!empty($stacktrace)) {
            $message .= $stacktrace  . PHP_EOL .  PHP_EOL;
        } elseif (!empty($context)) {
            $message .= $context  . PHP_EOL .  PHP_EOL;
        }

        $this->guzzle->request('POST', $this->webhook, [
            RequestOptions::JSON => [
                'message' =>  $message
            ]
        ]);
    }

    protected function getContext(array|LogRecord $record): ?string
    {
        if ($this->hasStacktrace($record)) {
            return null;
        }

        $context = $record['context'] ?? null;

        if (!$context) {
            return null;
        }

        if (!is_array($context) && !is_object($context)) {
            return null;
        }

        $text = $this->normalizeContext($context);

        return "```php\n" . $text . "\n```";
    }

    protected function getStacktrace(array|LogRecord $record): ?string
    {
        if (!$this->hasStacktrace($record)) {
            return null;
        }
        /** @var Throwable $exception */
        $exception = $record['context']['exception'];

        $stacktrace =
            ($this->withTraceMarkup ? '**' : '')
            . "{$exception->getFile()}:{$exception->getLine()} (code {$exception->getCode()})"
            . ($this->withTraceMarkup ? '**' : '')
            . "\n\n";

        if ($this->withTrace) {
            $stacktrace .= "Stacktrace:\n";

            foreach ($exception->getTrace() as $key => $traceLine) {
                if (!$this->withTraceVendorLines) {
                    if (str_contains($traceLine['file'], base_path('vendor'))) {
                        continue;
                    }
                }

                $args = [];
                if (isset($traceLine['args']) && $traceLine['args']) {
                    foreach ($traceLine['args'] as $value) {
                        if (is_string($value)) {
                            $args[] = "`" . $value . "`";
                        } elseif (is_bool($value)) {
                            $args[] = $value ? "true" : "false";
                        } elseif (is_null($value)) {
                            $args[] = "null";
                        } elseif (is_int($value)) {
                            $args[] = $value;
                        } elseif (is_object($value)) {
                            $args[] = 'Object(' . get_class($value) . ')';
                        } elseif (is_array($value)) {
                            $args[] = 'Array';
                        } else {
                            $args[] = (string)$value;
                        }
                    }
                }

                $stacktrace .= "\n";

                $line =
                    '#' . $key . "\n"
                    . ($traceLine['file'] ?? '')
                    . '(' . $traceLine['line'] . ')'
                    . ': '
                    . ($traceLine['class'] ?? '')
                    . ($traceLine['type'] ?? '')
                    . ($traceLine['function'] ?? '');

                if ($args) {
                    $line .= '(' . implode(', ', $args) . ')';
                }

                if ($this->withTraceMarkup) {
                    if (str_contains($traceLine['file'], app_path())) {
                        $line = '**' . $line . '**';
                    }
                }

                $stacktrace .= $line;
            }
        }

        return $stacktrace;
    }

    protected function getHeader(array|LogRecord $record): string
    {
        return ($record['level'] >= 400 ? "💥 " : "ℹ️ ") . $record['level'] . " " . $this->name . ": ";
    }

    private function normalizeContext(array|object $context, int $depth = 0): string
    {
        if (is_array($context)) {
            $openWith = '[';
            $closeWith = ']';
            $delimiter = ': ';
        } elseif (is_object($context)) {
            $openWith = '{';
            $closeWith = '}';
            $delimiter = ': ';
        } else {
            return '';
        }

        $text = '';

        $class = is_object($context) ? get_class($context) : '';

        if (is_object($context)) {
            if ($context instanceof Model) {
                $context = $context->toArray();
            } elseif ($context instanceof Collection) {
                $context = $context->toArray();
            } elseif ($context instanceof stdClass) {
                $context = (array)$context;
            } else {
                $context = (array)$context; // not tested properly
            }
        }

        if ($class) {
            $text .= "$class: ";
        }

        $text .= "$openWith\n";

        if ($depth > $this->maxDepth) {
            $text .= str_repeat("\t", $depth + 1);
            $text .= "...\n";
        } else {
            $isAssoc = Arr::isAssoc($context);

            foreach ($context as $key => $value) {
                $text .= str_repeat("\t", $depth + 1);
                if ($isAssoc) {
                    $text .= $key;
                    $text .= $delimiter;
                }

                if (is_array($value) || is_object($value)) {
                    $depth++;
                    $text .= $this->normalizeContext($value, $depth);
                    $depth--;
                } else {
                    if (is_string($value)) {
                        $text .= "`" . $value . "`";
                    } elseif (is_bool($value)) {
                        $text .= $value ? "true" : "false";
                    } elseif (is_null($value)) {
                        $text .= "null";
                    } elseif (is_int($value)) {
                        $text .= $value;
                    } else {
                        $text .= $value;
                    }

                    if ($key < count($context)) {
                        $text .= ",";
                    }
                    $text .= "\n";
                }
            }
        }

        $text .= str_repeat("\t", $depth);
        $text .= "$closeWith\n";

        return $text;
    }

    private function hasStacktrace(array|LogRecord $record) : bool
    {
        return is_subclass_of($record['context']['exception'] ?? '', Throwable::class);
    }
}
