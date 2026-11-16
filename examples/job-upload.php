<?php

// Jobs and tasks, the same code as with the CloudConvert SDK: upload a file, convert it to PDF, download the result.
//   SWITCHFILE_API_KEY=sf_test_... php examples/job-upload.php report.docx

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Models\Job;
use Switchfile\Models\Task;
use Switchfile\Switchfile;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php examples/job-upload.php <file>\n");
    exit(1);
}

$switchfile = new Switchfile(['api_key' => getenv('SWITCHFILE_API_KEY')]);

$job = (new Job())
    ->addTask(new Task('import/upload', 'upload-my-file'))
    ->addTask((new Task('convert', 'convert-my-file'))->set('input', 'upload-my-file')->set('output_format', 'pdf'))
    ->addTask((new Task('export/url', 'export-my-file'))->set('input', 'convert-my-file'));
$switchfile->jobs()->create($job);

$uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
$switchfile->tasks()->upload($uploadTask, fopen($argv[1], 'r'), basename($argv[1]));

$switchfile->jobs()->wait($job);
if ($job->getStatus() !== Job::STATUS_FINISHED) {
    $failed = $job->getTasks()->whereStatus(Task::STATUS_ERROR)[0];
    fwrite(STDERR, "job failed: {$failed->getCode()} {$failed->getMessage()}\n");
    exit(1);
}
foreach ($job->getExportUrls() as $file) {
    $source = $switchfile->getHttpTransport()->download($file->url)->detach();
    $dest = fopen($file->filename, 'w');
    stream_copy_to_stream($source, $dest);
    echo "saved {$file->filename}\n";
}
