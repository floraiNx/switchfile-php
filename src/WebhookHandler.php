<?php

declare(strict_types=1);

namespace Switchfile;

use Switchfile\Exceptions\SignatureVerificationException;
use Switchfile\Exceptions\UnexpectedDataException;
use Switchfile\Models\WebhookEvent;

/**
 * Verify webhooks, like $cloudconvert->webhookHandler(). The API signs the raw body with HMAC-SHA256 (hex):
 * header CloudConvert-Signature for /v2 (job.*), Switchfile-Signature for /v1 (conversion.*).
 */
class WebhookHandler
{
    public function verify(string $payload, ?string $signature, string $signingSecret): bool
    {
        if ($signature === null || $signature === '' || $signingSecret === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $payload, $signingSecret), strtolower(trim($signature)));
    }

    /**
     * @throws SignatureVerificationException if the signature does not match
     * @throws UnexpectedDataException if the body is not an event
     */
    public function constructEvent(string $payload, ?string $signature, string $signingSecret): WebhookEvent
    {
        if (!$this->verify($payload, $signature, $signingSecret)) {
            throw new SignatureVerificationException('The webhook signature does not match the payload.', 'INVALID_SIGNATURE');
        }
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedDataException('The webhook payload is not valid JSON.', 'INVALID_PAYLOAD', previous: $e);
        }
        if (!is_array($data) || !is_string($data['event'] ?? null)) {
            throw new UnexpectedDataException('The webhook payload has no event name.', 'INVALID_PAYLOAD');
        }
        return new WebhookEvent($data);
    }

    /**
     * From a PSR-7 request (getBody(), getHeaderLine()) as in the CloudConvert SDK.
     */
    public function constructEventFromRequest(object $request, string $signingSecret): WebhookEvent
    {
        $signature = $request->getHeaderLine('CloudConvert-Signature');
        if ($signature === '') {
            $signature = $request->getHeaderLine('Switchfile-Signature');
        }
        return $this->constructEvent((string) $request->getBody(), $signature, $signingSecret);
    }
}
