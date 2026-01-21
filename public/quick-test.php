<?php
// Standalone Odoo Test (no Phalcon dependency)
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Standalone Odoo Test</h1>";

// Odoo credentials
$odooUrl = 'http://odoo:8069';
$odooDb = 'test';
$odooUsername = 'farizlahya@gmail.com';
$odooPassword = 'password';

function odooXmlRpcCall($endpoint, $method, $params) {
    $xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
    
    foreach ($params as $param) {
        $xml .= '<param>' . odooEncodeValue($param) . '</param>';
    }
    
    $xml .= '</params></methodCall>';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml',
        'Content-Length: ' . strlen($xml)
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL Error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: $httpCode");
    }
    
    return $response;
}

function odooEncodeValue($value) {
    if (is_int($value)) {
        return '<value><int>' . $value . '</int></value>';
    } elseif (is_string($value)) {
        return '<value><string>' . htmlspecialchars($value) . '</string></value>';
    } elseif (is_array($value)) {
        if (empty($value)) {
            return '<value><struct></struct></value>';
        }
        $xml = '<value><array><data>';
        foreach ($value as $item) {
            $xml .= odooEncodeValue($item);
        }
        $xml .= '</data></array></value>';
        return $xml;
    }
    return '<value><struct></struct></value>';
}

try {
    // Test 1: Authentication
    echo "<h2>1. Testing Authentication...</h2>";
    
    $authResponse = odooXmlRpcCall(
        $odooUrl . '/xmlrpc/2/common',
        'authenticate',
        [$odooDb, $odooUsername, $odooPassword, []]
    );
    
    echo "<p><strong>Auth Response:</strong></p>";
    echo "<pre>" . htmlspecialchars($authResponse) . "</pre>";
    
    if (preg_match('/<int>(\d+)<\/int>/', $authResponse, $matches)) {
        $uid = (int)$matches[1];
        echo "<p style='color:green;'><strong>✓ Authentication Success! UID: $uid</strong></p>";
        
        // Test 2: Count Products
        echo "<h2>2. Counting Products...</h2>";
        
        $countXml = '<?xml version="1.0"?>
        <methodCall>
            <methodName>execute_kw</methodName>
            <params>
                <param><value><string>' . $odooDb . '</string></value></param>
                <param><value><int>' . $uid . '</int></value></param>
                <param><value><string>' . $odooPassword . '</string></value></param>
                <param><value><string>product.product</string></value></param>
                <param><value><string>search_count</string></value></param>
                <param><value><array><data><value><array><data></data></array></value></data></array></value></param>
            </params>
        </methodCall>';
        
        $ch = curl_init($odooUrl . '/xmlrpc/2/object');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $countXml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        
        $countResponse = curl_exec($ch);
        curl_close($ch);
        
        echo "<pre>" . htmlspecialchars($countResponse) . "</pre>";
        
        if (preg_match('/<int>(\d+)<\/int>/', $countResponse, $matches)) {
            $productCount = $matches[1];
            echo "<p><strong>Total Products:</strong> $productCount</p>";
        }
        
        echo "<h2 style='color:green;'>✓ Connection Working!</h2>";
        echo "<p><a href='/odoo-dashboard'>Test Dashboard</a></p>";
        
    } elseif (preg_match('/<boolean>0<\/boolean>/', $authResponse)) {
        echo "<p style='color:red;'><strong>✗ Authentication Failed - Invalid credentials!</strong></p>";
        echo "<p>Please check email/password in Odoo.</p>";
    } else {
        echo "<p style='color:red;'><strong>✗ Unexpected response!</strong></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>
