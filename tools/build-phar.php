<?php

declare(strict_types=1);

if(PHP_SAPI !== 'cli'){
    fwrite(STDERR, "This build script must run from the command line.\n");
    exit(1);
}
if(!extension_loaded('phar') || (bool) ini_get('phar.readonly')){
    fwrite(STDERR, "The PHAR extension must be enabled and phar.readonly=0 must be set.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$output = $argv[1] ?? ($projectRoot . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'BankSystem.phar');
$outputDirectory = dirname($output);

if(!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)){
    fwrite(STDERR, "Could not create output directory: " . $outputDirectory . "\n");
    exit(1);
}
if(is_link($output)){
    fwrite(STDERR, "Refusing to overwrite a symbolic-link output path.\n");
    exit(1);
}
if(file_exists($output) && !@unlink($output)){
    fwrite(STDERR, "Could not replace existing output file: " . $output . "\n");
    exit(1);
}

$files = [];
$pluginManifest = $projectRoot . DIRECTORY_SEPARATOR . 'plugin.yml';
if(!is_file($pluginManifest) || is_link($pluginManifest)){
    fwrite(STDERR, "Required plugin.yml is missing or is a symbolic link.\n");
    exit(1);
}
$files['plugin.yml'] = $pluginManifest;

foreach(['src', 'resources'] as $directory){
    $absoluteDirectory = $projectRoot . DIRECTORY_SEPARATOR . $directory;
    if(!is_dir($absoluteDirectory) || is_link($absoluteDirectory)){
        fwrite(STDERR, "Required source directory is missing or is a symbolic link: " . $directory . "\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
    );
    foreach($iterator as $entry){
        if($entry->isLink()){
            fwrite(STDERR, "Refusing to package a symbolic link: " . $entry->getPathname() . "\n");
            exit(1);
        }
        if(!$entry->isFile()){
            continue;
        }
        $relativePath = substr($entry->getPathname(), strlen($projectRoot) + 1);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        $files[$relativePath] = $entry->getPathname();
    }
}

ksort($files, SORT_STRING);

try{
    $phar = new Phar($output, FilesystemIterator::CURRENT_AS_FILEINFO, basename($output));
    $phar->startBuffering();
    $phar->setSignatureAlgorithm(Phar::SHA256);
    foreach($files as $archivePath => $sourcePath){
        $phar->addFile($sourcePath, $archivePath);
    }
    $phar->setStub("<?php\n__HALT_COMPILER();");
    $phar->stopBuffering();
    unset($phar);
}catch(Throwable $exception){
    if(is_file($output)){
        @unlink($output);
    }
    fwrite(STDERR, "Could not build plugin PHAR: " . $exception->getMessage() . "\n");
    exit(1);
}

if(!is_file($output) || filesize($output) === 0){
    fwrite(STDERR, "The plugin PHAR was not created correctly.\n");
    exit(1);
}

fwrite(STDOUT, "Built " . $output . " (" . filesize($output) . " bytes, " . count($files) . " files).\n");
