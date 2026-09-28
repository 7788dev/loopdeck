<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

$app = new think\App(dirname(__DIR__) . '/');
$root = dirname(__DIR__);
$temporary = tempnam(sys_get_temp_dir(), 'loopdeck-template-');
$count = 0;
try {
    foreach (['index', 'admin', 'install'] as $module) {
        $viewPath = $root . '/app/' . $module . '/view/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'html' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'sport' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $engine = new think\Template(['view_path' => $viewPath]);
            $source = file_get_contents($file->getPathname());
            $engine->parse($source);
            file_put_contents($temporary, $source);
            $process = proc_open([PHP_BINARY, '-l', $temporary], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException($file->getPathname() . ': ' . $output);
            }
            $count++;
        }
    }
} finally {
    if (is_file($temporary)) unlink($temporary);
}
echo "Compiled and linted {$count} active templates\n";
