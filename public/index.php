<?php
declare(strict_types=1);

error_reporting(E_ALL);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

try {
    /**
     * Check if requesting a static file (CSS, JS, Images, etc.)
     * If so, serve it directly without routing through Phalcon
     */
    $requestUri = $_SERVER['REQUEST_URI'];
    $publicPath = dirname(__FILE__);
    
    // Remove query string
    $path = parse_url($requestUri, PHP_URL_PATH);
    
    // Check if it's a static file
    $staticExtensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'];
    $fileExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    
    if (!empty($fileExt) && in_array($fileExt, $staticExtensions)) {
        // Try to serve static file
        $filePath = $publicPath . $path;
        if (file_exists($filePath) && is_file($filePath)) {
            // Determine MIME type
            $mimeTypes = [
                'css' => 'text/css',
                'js' => 'application/javascript',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'eot' => 'application/vnd.ms-fontobject',
            ];
            
            $mimeType = $mimeTypes[$fileExt] ?? 'application/octet-stream';
            header('Content-Type: ' . $mimeType);
            header('Cache-Control: public, max-age=3600');
            readfile($filePath);
            exit;
        }
    }
    
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
     * Handle routes with custom DI registration
     */
    $di->setShared('router', function () {
        return include APP_PATH . '/config/router.php';
    });

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
    
    // Prefer URL from rewrite (_url) when available, otherwise use REQUEST_URI
    $uri = $_GET['_url'] ?? $_SERVER['REQUEST_URI'];
    error_log("DEBUG: Handling URI -> " . $uri . " Method: " . $_SERVER['REQUEST_METHOD']);
    $response = $application->handle($uri);
    
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
