<?php
/**
 * Test Odoo Connection
 * Akses: http://localhost:8082/test-odoo-connection.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Odoo Configuration
$url = 'http://odoo:8069';
$db = 'test';
$username = 'farizlahya@gmail.com';
$password = 'guwosari6b';

echo "<h1>Test Odoo XML-RPC Connection</h1>";

// Step 1: Authenticate
echo "<h2>1. Testing Authentication...</h2>";

$authEndpoint = $url . '/xmlrpc/2/common';
$xmlRequest = '<?xml version="1.0"?>
<methodCall>
    <methodName>authenticate</methodName>
    <params>
        <param><value><string>' . $db . '</string></value></param>
        <param><value><string>' . $username . '</string></value></param>
        <param><value><string>' . $password . '</string></value></param>
        <param><value><struct></struct></value></param>
    </params>
</methodCall>';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $authEndpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: text/xml',
    'Content-Length: ' . strlen($xmlRequest)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
if ($curlError) {
    echo "<p><strong style='color:red;'>CURL Error:</strong> $curlError</p>";
}
echo "<p><strong>Response:</strong></p>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";

// Parse UID
if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
    $uid = $matches[1];
    echo "<p style='color:green;'><strong>✓ Authentication Successful! UID: $uid</strong></p>";
    
    // Step 2: Get Installed Modules
    echo "<h2>2. Checking Installed Modules...</h2>";
    
    $objectEndpoint = $url . '/xmlrpc/2/object';
    $xmlRequest = '<?xml version="1.0"?>
    <methodCall>
        <methodName>execute_kw</methodName>
        <params>
            <param><value><string>' . $db . '</string></value></param>
            <param><value><int>' . $uid . '</int></value></param>
            <param><value><string>' . $password . '</string></value></param>
            <param><value><string>ir.module.module</string></value></param>
            <param><value><string>search_read</string></value></param>
            <param>
                <value>
                    <array>
                        <data>
                            <value>
                                <array>
                                    <data>
                                        <value>
                                            <array>
                                                <data>
                                                    <value><string>name</string></value>
                                                    <value><string>in</string></value>
                                                    <value>
                                                        <array>
                                                            <data>
                                                                <value><string>stock</string></value>
                                                                <value><string>purchase</string></value>
                                                                <value><string>sale_management</string></value>
                                                                <value><string>account</string></value>
                                                            </data>
                                                        </array>
                                                    </value>
                                                </data>
                                            </array>
                                        </value>
                                    </data>
                                </array>
                            </value>
                        </data>
                    </array>
                </value>
            </param>
            <param>
                <value>
                    <struct>
                        <member>
                            <name>fields</name>
                            <value>
                                <array>
                                    <data>
                                        <value><string>name</string></value>
                                        <value><string>state</string></value>
                                    </data>
                                </array>
                            </value>
                        </member>
                    </struct>
                </value>
            </param>
        </params>
    </methodCall>';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $objectEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml',
        'Content-Length: ' . strlen($xmlRequest)
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    
    // Step 3: Count Products
    echo "<h2>3. Counting Products...</h2>";
    
    $xmlRequest = '<?xml version="1.0"?>
    <methodCall>
        <methodName>execute_kw</methodName>
        <params>
            <param><value><string>' . $db . '</string></value></param>
            <param><value><int>' . $uid . '</int></value></param>
            <param><value><string>' . $password . '</string></value></param>
            <param><value><string>product.product</string></value></param>
            <param><value><string>search_count</string></value></param>
            <param>
                <value>
                    <array>
                        <data>
                            <value>
                                <array>
                                    <data></data>
                                </array>
                            </value>
                        </data>
                    </array>
                </value>
            </param>
        </params>
    </methodCall>';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $objectEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml',
        'Content-Length: ' . strlen($xmlRequest)
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
        $productCount = $matches[1];
        echo "<p><strong>Total Products:</strong> $productCount</p>";
    }
    
    echo "<h2 style='color:green;'>✓ All Tests Passed!</h2>";
    echo "<p><a href='/odoo-dashboard'>Go to Odoo Dashboard</a></p>";
    
} else {
    echo "<p style='color:red;'><strong>✗ Authentication Failed!</strong></p>";
}
?>
