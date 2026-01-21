<?php

function odooExecute($url, $db, $username, $password, $model, $method, $args = [], $kwargs = []) {
    $common = xmlrpc_encode_request('authenticate', [$db, $username, $password, []]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: text/xml',
        'content' => $common
    ]]);
    
    $response = file_get_contents("$url/xmlrpc/2/common", false, $context);
    $uid = xmlrpc_decode($response);
    
    if (!$uid) {
        throw new Exception("Authentication failed");
    }
    
    $params = array_merge([$db, $uid, $password, $model, $method], $args);
    if (!empty($kwargs)) {
        $params[] = $kwargs;
    }
    
    $request = xmlrpc_encode_request('execute_kw', $params);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => 'Content-Type: text/xml',
        'content' => $request
    ]]);
    
    $response = file_get_contents("$url/xmlrpc/2/object", false, $context);
    return xmlrpc_decode($response);
}

$url = 'http://odoo:8069';
$db = 'test';
$username = 'farizlahya@gmail.com';
$password = 'password';

echo "=== Checking Products and Stock ===\n\n";

try {
    // Get products with stock info
    $products = odooExecute($url, $db, $username, $password, 
        'product.product', 'search_read', 
        [[]],
        ['fields' => ['name', 'default_code', 'qty_available', 'qty_on_hand', 'virtual_available', 'type']]
    );
    
    echo "Total products: " . count($products) . "\n\n";
    
    foreach ($products as $product) {
        echo "Product: {$product['name']}\n";
        echo "  Code: " . ($product['default_code'] ?: 'N/A') . "\n";
        echo "  Type: {$product['type']}\n";
        echo "  Qty Available: {$product['qty_available']}\n";
        if (isset($product['qty_on_hand'])) {
            echo "  Qty On Hand: {$product['qty_on_hand']}\n";
        }
        if (isset($product['virtual_available'])) {
            echo "  Virtual Available: {$product['virtual_available']}\n";
        }
        echo "\n";
    }
    
    // Check stock quants
    echo "\n=== Checking Stock Quants ===\n\n";
    $quants = odooExecute($url, $db, $username, $password,
        'stock.quant', 'search_read',
        [[]],
        ['fields' => ['product_id', 'location_id', 'quantity', 'reserved_quantity'], 'limit' => 20]
    );
    
    echo "Total quants: " . count($quants) . "\n\n";
    foreach ($quants as $quant) {
        echo "Product: " . (is_array($quant['product_id']) ? $quant['product_id'][1] : $quant['product_id']) . "\n";
        echo "  Location: " . (is_array($quant['location_id']) ? $quant['location_id'][1] : $quant['location_id']) . "\n";
        echo "  Quantity: {$quant['quantity']}\n";
        echo "  Reserved: {$quant['reserved_quantity']}\n\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
