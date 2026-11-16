<?php

// Convert several local files with one batch and save all results as one ZIP file.
//   SWITCHFILE_API_KEY=sf_test_... php examples/batch-zip.php jpg photo1.heic photo2.heic

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Switchfile;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php examples/batch-zip.php <format> <file> [file ...]\n");
    exit(1);
}

$to = $argv[1];
$switchfile = new Switchfile();
$conversions = [];
foreach (array_slice($argv, 2) as $file) {
    $conversions[] = ['upload_id' => $switchfile->uploads()->create($file)['upload_id'], 'to' => $to];
}
$batch = $switchfile->batches()->create(['conversions' => $conversions, 'archive' => 'zip'], ['wait' => true]);
echo "{$batch['counts']['finished']} of {$batch['counts']['total']} finished\n";
if (($batch['archive']['url'] ?? null) !== null) {
    $switchfile->download($batch['archive'], 'results.zip');
    echo "saved results.zip\n";
}
