<?php

declare(strict_types=1);

namespace Switchfile\Resources;

/** Small helpers for the /v1 resources: idempotency keys and the value for ?wait. */
final class Ids
{
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function waitSeconds(?int $wait): ?int
    {
        return $wait !== null && $wait > 0 ? min(ConversionsResource::WAIT_SECONDS, $wait) : null;
    }
}
