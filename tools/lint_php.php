<?php

$root = dirname(__DIR__);
$excludeDirs = [
    '.git',
    'node_modules',
    'vendor',
    'uploads',
    'logs',
    'cache',
    'backups'
];

$phpBinary = PHP_BINARY ?: 'php';
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($excludeDirs) {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $excludeDirs, true);
            }

            return true;
        }
    )
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        continue;
    }

    $files[] = $path;
}

sort($files, SORT_STRING);

$failures = [];
foreach ($files as $file) {
    $command = escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        $failures[$file] = implode(PHP_EOL, $output);
    }
}

if ($failures) {
    fwrite(STDERR, 'PHP lint failed for ' . count($failures) . " file(s)." . PHP_EOL);
    foreach ($failures as $file => $message) {
        fwrite(STDERR, $file . PHP_EOL . $message . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, 'PHP lint passed for ' . count($files) . " file(s)." . PHP_EOL);
