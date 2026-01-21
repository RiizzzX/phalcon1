<?php

$loader = new \Phalcon\Autoload\Loader();

/**
 * We're a registering a set of directories taken from the configuration file
 */
$loader->setDirectories(
    [
        $config->application->controllersDir,
        $config->application->modelsDir
    ]
);

// Register namespaces so namespaced classes (App\\Controllers, App\\Models) are autoloaded
$loader->setNamespaces([
    'App\\Controllers' => $config->application->controllersDir,
    'App\\Models'      => $config->application->modelsDir,
    'App\\Library'     => $config->application->libraryDir,
]);

$loader->register();
