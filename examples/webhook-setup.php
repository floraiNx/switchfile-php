<?php

// Point the account webhook at your URL and save the signing secret to a private file.
//   SWITCHFILE_API_KEY=sf_test_... php examples/webhook-setup.php https://example.com/hooks/switchfile

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Switchfile;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php examples/webhook-setup.php <url>\n");
    exit(1);
}

$switchfile = new Switchfile();
foreach ($switchfile->webhooks()->list() as $old) {
    $switchfile->webhooks()->delete($old['id']);
}
$webhook = $switchfile->webhooks()->create([
    'url' => $argv[1],
    'events' => ['job.finished', 'job.failed', 'conversion.finished', 'conversion.failed'],
]);
echo "webhook {$webhook['id']} for {$webhook['url']}\n";
// The API returns the secret only once: write it to a file only you can read, never to a log.
$secretFile = 'webhook-signing-secret.txt';
touch($secretFile);
chmod($secretFile, 0600);
file_put_contents($secretFile, $webhook['signing_secret'] . "\n");
echo "signing secret saved to {$secretFile}\n";
