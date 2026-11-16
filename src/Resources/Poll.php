<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Exceptions\SwitchfileException;

/** Repeated polling until a final status, with a growing pause (up to 5 s). */
final class Poll
{
    /**
     * @template T
     * @param callable(): T $load
     * @param callable(T): bool $done
     * @return T
     */
    public static function until(callable $load, callable $done, ?float $timeout, float $interval): mixed
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        while (true) {
            $item = $load();
            if ($done($item)) {
                return $item;
            }
            $sleep = $interval;
            if ($deadline !== null) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    throw new SwitchfileException("Still not finished after waiting {$timeout} seconds.", 'TIMEOUT');
                }
                $sleep = min($sleep, $left);
            }
            usleep((int) ($sleep * 1_000_000));
            $interval = min(5.0, $interval * 1.5);
        }
    }
}
