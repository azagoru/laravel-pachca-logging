<?php

namespace SavenkovDev\PachcaLogger;

use Monolog\Level;
use Monolog\Logger;

class PachcaLogger
{
    public function __invoke(array $config): Logger
    {
        $log = new Logger('pachca');

        $log->pushHandler(new PachcaHandler($config['webhook'], config('app.name'), $config['level'] ?? Level::Debug, true, $config['maxDepth'] ?? 2));

        return $log;
    }
}
