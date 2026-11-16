<?php

// Receive webhooks and check their signature, e.g. with: php -S 0.0.0.0:8080 examples/webhook.php
//   SWITCHFILE_WEBHOOK_SECRET=...

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Exceptions\SignatureVerificationException;
use Switchfile\Exceptions\UnexpectedDataException;
use Switchfile\Switchfile;

$switchfile = new Switchfile();
$payload = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_SWITCHFILE_SIGNATURE'] ?? $_SERVER['HTTP_CLOUDCONVERT_SIGNATURE'] ?? '';

try {
    $event = $switchfile->webhookHandler()->constructEvent($payload, $signature, (string) getenv('SWITCHFILE_WEBHOOK_SECRET'));
} catch (SignatureVerificationException | UnexpectedDataException $e) {
    http_response_code(400);
    exit();
}

if ($job = $event->getJob()) {
    error_log("{$event->getEvent()} {$job->getId()} {$job->getStatus()}");
} else {
    $conversion = $event->getConversion();
    error_log("{$event->getEvent()} {$conversion['id']} " . ($conversion['download_url'] ?? ''));
}
http_response_code(200);
