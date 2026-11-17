<?php

namespace Azagoru\PachcaLogging;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Throwable;

class PachcaHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly string $webhook,
        private readonly string $name,
        int|string|Level        $level = Level::Debug,
        bool                    $bubble = true,
        private readonly int    $maxDepth = 2,
        private readonly bool   $withTrace = true,
        private readonly bool   $withTraceMarkup = true,
        private readonly bool   $withTraceVendorLines = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * @throws GuzzleException
     */
    protected function write(array|LogRecord $record): void
    {
        $header     = $this->getHeader($record);
        $stacktrace = $this->getStackTrace($record);
        $context    = $this->getContext($record);
        $extra      = $this->getExtra($record);

        $message = $header;
        if ($record['message']) {
            $message .= $record['message'] . $this->addEol();
        }

        $code = '';
        if (!empty($stacktrace)) {
            $message .= $stacktrace  . $this->addEol();
        } elseif (!empty($context)) {
            $code .= $context;
        }

        if ($extra) {
            $code .= $extra;
        }

        if ($code) {
            $code = "```php\n" . $code . "\n```";
        }

        $message .= $code . $this->addEol();

        try {
            (new Client())->request('POST', $this->webhook, [
                RequestOptions::JSON => [
                    'message' => $message
                ]
            ]);
        } catch (Throwable) {
            //
        }
    }

    protected function getHeader(array|LogRecord $record): string
    {
        return ($record['level'] >= 400 ? "💥 " : "ℹ️ ") . $record['level'] . " " . $this->name . ": ";
    }

    protected function getContext(array|LogRecord $record): ?string
    {
        return $this->getTextFromRecord($record, 'context');
    }

    protected function getExtra(array|LogRecord $record): ?string
    {
        return $this->getTextFromRecord($record, 'extra');
    }

    private function getTextFromRecord(array|LogRecord $record, string $property): ?string
    {
        if ($this->hasStackTrace($record)) {
            return null;
        }

        $value = $record[$property] ?? null;
        if (!$value) {
            return null;
        }

        $text = '';
        if (is_array($value) || is_object($value)) {
            $text = $this->normalizeContext($value);
        }

        return $text;
    }

    protected function getStackTrace(array|LogRecord $record): ?string
    {
        if (!$this->hasStackTrace($record)) {
            return null;
        }

        /** @var Throwable $exception */
        $exception = $record['context']['exception'];

        $stacktrace =
            ($this->withTraceMarkup ? '**' : '')
            . "{$exception->getFile()}:{$exception->getLine()} (code {$exception->getCode()})"
            . ($this->withTraceMarkup ? '**' : '')
            . "\n\n";

        if (!$this->withTrace) {
            return $stacktrace;
        }

        $trace = $exception->getTrace();
        if (empty($trace)) {
            $stacktrace .= "Stack trace is empty.";

            return $stacktrace;
        }

        $stacktrace .= "Stack trace:\n";

        foreach ($trace as $key => $traceLine) {
            if (!$this->withTraceVendorLines) {
                if (
                    isset($traceLine['file'])
                    && str_contains($traceLine['file'], base_path('vendor'))
                ) {
                    continue;
                }
            }

            $stacktrace .= "\n";

            $stacktrace .= $this->getTraceLine($key, $traceLine);
        }

        return $stacktrace;
    }

    private function getTraceLine(int $key, array $traceLine): string
    {
        $line =
            '#' . $key . "\n"
            . ($traceLine['file'] ?? '')
            . (isset($traceLine['line']) ? '(' . $traceLine['line'] . ')' : '');

        if (
            isset($traceLine['class'])
            || isset($traceLine['type'])
            || isset($traceLine['function'])
        ) {
            $line .=
                "\n"
                . ($traceLine['class'] ?? '')
                . ($traceLine['type'] ?? '')
                . ($traceLine['function'] ?? '');
        }

        $args = $this->getArgs($traceLine);
        if ($args) {
            $line .= '(' . implode(', ', $args) . ')';
        }

        if ($this->withTraceMarkup) {
            if (
                isset($traceLine['file'])
                && str_contains($traceLine['file'], app_path())
            ) {
                $line = '**' . $line . '**';
            }
        }

        return $line;
    }

    private function getArgs(array $traceLine): array
    {
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

        return $args;
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

        $text = '';
        if ($class) {
            $text .= "$class: ";
        }

        if ($depth > $this->maxDepth) {
            $text .= str_repeat("\t", $depth + 1);
            $text .= "...\n";
        } else {
            $isAssoc = !array_is_list($context);

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

        if ($text) {
            return
                "$openWith\n"
                . $text
                . "$closeWith\n";
        }

        return $text;
    }

    private function hasStackTrace(array|LogRecord $record) : bool
    {
        return is_subclass_of($record['context']['exception'] ?? '', Throwable::class);
    }

    private function addEol(): string
    {
        return PHP_EOL .  PHP_EOL;
    }
}
