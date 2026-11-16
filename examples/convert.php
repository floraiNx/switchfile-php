<?php

// Convert a local file with one call and save the result next to it.
//   SWITCHFILE_API_KEY=sf_test_... php examples/convert.php talk.mkv mp4

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Switchfile;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php examples/convert.php <file> <format>\n");
    exit(1);
}

$switchfile = new Switchfile();
$conversion = $switchfile->convert($argv[1], $argv[2]);
foreach ($conversion['result']['files'] as $file) {
    $switchfile->download($file['url'], $file['filename']);
    echo "saved {$file['filename']} ({$file['size']} bytes)\n";
}
