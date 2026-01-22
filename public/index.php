<?php
declare(strict_types=1);

error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

try {
    /**
     * The FactoryDefault Dependency Injector automatically registers
     * the services that provide a full stack framework.
     */
    $di = new \Phalcon\Di\FactoryDefault();

    /**
     * Read services
     */
    include APP_PATH . '/config/services.php';

    /**
     * Handle routes
     */
    include APP_PATH . '/config/router.php';

    /**
     * Get config service for use in inline setup below
     */
    $config = $di->getConfig();

    /**
     * Include Autoloader
     */
    include APP_PATH . '/config/loader.php';

    /**
     * Handle the request
     */
    $application = new \Phalcon\Mvc\Application($di);
    
    // Debug: log request URI
    error_log("REQUEST_URI: " . $_SERVER['REQUEST_URI']);
    error_log("DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT']);
    
    $response = $application->handle($_SERVER['REQUEST_URI']);
    
    // Debug: log matched route
    error_log("Matched Controller: " . $application->getDI()->getDispatcher()->getControllerName());
    error_log("Matched Action: " . $application->getDI()->getDispatcher()->getActionName());

    echo $response->getContent();
} catch (\Exception $e) {
    error_log("Exception: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    echo $e->getMessage() . '<br>';
    echo '<pre>' . $e->getTraceAsString() . '</pre>';
}
