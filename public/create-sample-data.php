<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Create Sample Data in Odoo</h1>";

// Odoo credentials
$odooUrl = 'http://odoo:8069';
$odooDb = 'test';
$odooUsername = 'farizlahya@gmail.com';
$odooPassword = 'password';

function odooAuth($url, $db, $username, $password) {
    $xml = '<?xml version="1.0"?>
    <methodCall>
        <methodName>authenticate</methodName>
        <params>
            <param><value><string>' . $db . '</string></value></param>
            <param><value><string>' . $username . '</string></value></param>
            <param><value><string>' . $password . '</string></value></param>
            <param><value><struct></struct></value></param>
        </params>
    </methodCall>';
    
    $ch = curl_init($url . '/xmlrpc/2/common');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
        return (int)$matches[1];
    }
    return false;
}

function odooExecute($url, $db, $uid, $password, $model, $method, $args = []) {
    $argsXml = '';
    if (!empty($args)) {
        $argsXml = '<value><array><data>';
        foreach ($args as $arg) {
            if (is_array($arg)) {
                $argsXml .= '<value><struct>';
                foreach ($arg as $key => $value) {
                    $argsXml .= '<member><name>' . $key . '</name>';
                    if (is_string($value)) {
                        $argsXml .= '<value><string>' . htmlspecialchars($value) . '</string></value>';
                    } elseif (is_int($value) || is_float($value)) {
                        $argsXml .= '<value><double>' . $value . '</double></value>';
                    } elseif (is_bool($value)) {
                        $argsXml .= '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
                    }
                    $argsXml .= '</member>';
                }
                $argsXml .= '</struct></value>';
            }
        }
        $argsXml .= '</data></array></value>';
    }
    
    $xml = '<?xml version="1.0"?>
    <methodCall>
        <methodName>execute_kw</methodName>
        <params>
            <param><value><string>' . $db . '</string></value></param>
            <param><value><int>' . $uid . '</int></value></param>
            <param><value><string>' . $password . '</string></value></param>
            <param><value><string>' . $model . '</string></value></param>
            <param><value><string>' . $method . '</string></value></param>
            <param><value><array><data>' . $argsXml . '</data></array></value></param>
        </params>
    </methodCall>';
    
    $ch = curl_init($url . '/xmlrpc/2/object');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

try {
    echo "<h2>1. Authenticating...</h2>";
    $uid = odooAuth($odooUrl, $odooDb, $odooUsername, $odooPassword);
    
    if (!$uid) {
        die("<p style='color:red;'>Authentication failed!</p>");
    }
    
    echo "<p style='color:green;'>✓ Authenticated as UID: $uid</p>";
    
    // Create Customers
    echo "<h2>2. Creating Sample Customers...</h2>";
    
    $customers = [
        ['name' => 'PT Maju Jaya', 'email' => 'info@majujaya.com', 'phone' => '021-12345678', 'customer_rank' => 1],
        ['name' => 'CV Sukses Makmur', 'email' => 'contact@suksesmakmur.com', 'phone' => '021-87654321', 'customer_rank' => 1],
        ['name' => 'UD Sejahtera', 'email' => 'admin@sejahtera.com', 'phone' => '021-55555555', 'customer_rank' => 1],
    ];
    
    foreach ($customers as $customer) {
        $response = odooExecute($odooUrl, $odooDb, $uid, $odooPassword, 'res.partner', 'create', [$customer]);
        
        if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
            echo "<p>✓ Created customer: {$customer['name']} (ID: {$matches[1]})</p>";
        }
    }
    
    // Create Suppliers
    echo "<h2>3. Creating Sample Suppliers...</h2>";
    
    $suppliers = [
        ['name' => 'PT Supplier Tech', 'email' => 'sales@suppliertech.com', 'phone' => '021-99999999', 'supplier_rank' => 1],
        ['name' => 'CV Distributor Prima', 'email' => 'info@distributorprima.com', 'phone' => '021-88888888', 'supplier_rank' => 1],
    ];
    
    foreach ($suppliers as $supplier) {
        $response = odooExecute($odooUrl, $odooDb, $uid, $odooPassword, 'res.partner', 'create', [$supplier]);
        
        if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
            echo "<p>✓ Created supplier: {$supplier['name']} (ID: {$matches[1]})</p>";
        }
    }
    
    // Create Products
    echo "<h2>4. Creating Sample Products...</h2>";
    
    $products = [
        ['name' => 'Laptop Dell XPS 13', 'list_price' => 15000000, 'type' => 'product'],
        ['name' => 'Mouse Logitech MX Master', 'list_price' => 1200000, 'type' => 'product'],
        ['name' => 'Keyboard Mechanical', 'list_price' => 800000, 'type' => 'product'],
        ['name' => 'Monitor LG 27 inch', 'list_price' => 3500000, 'type' => 'product'],
        ['name' => 'Webcam HD', 'list_price' => 600000, 'type' => 'product'],
    ];
    
    foreach ($products as $product) {
        $response = odooExecute($odooUrl, $odooDb, $uid, $odooPassword, 'product.product', 'create', [$product]);
        
        if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
            echo "<p>✓ Created: {$product['name']} (ID: {$matches[1]})</p>";
        } else {
            echo "<p style='color:orange;'>⚠ Failed to create: {$product['name']}</p>";
        }
    }
    
    echo "<h2 style='color:green;'>✓ Sample Data Created!</h2>";
    echo "<p><a href='/odoo-dashboard'>Go to Dashboard</a></p>";
    echo "<p><a href='/odoo-inventory'>View Products</a></p>";
    echo "<p><strong>Note:</strong> Equipment rental data harus dibuat melalui Odoo UI karena menggunakan custom module.</p>";
    echo "<p><a href='http://localhost:8069/web#action=equipment_rental.equipment_rental_action' target='_blank'>Create Equipment in Odoo</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}
?>
