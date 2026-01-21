#!/usr/bin/env php
<?php

$url = 'http://odoo:8069';
$db = 'test';
$username = 'farizlahya@gmail.com';
$password = 'password';

function odooRPC($url, $endpoint, $method, $params) {
    $xml = xmlrpc_encode_request($method, $params, ['encoding' => 'utf-8']);
    
    $ch = curl_init("$url/xmlrpc/2/$endpoint");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return xmlrpc_decode($response);
}

echo "=== Authenticating ===\n";
$uid = odooRPC($url, 'common', 'authenticate', [$db, $username, $password, []]);
echo "UID: $uid\n\n";

if (!$uid) {
    die("Authentication failed\n");
}

echo "=== Getting Products ===\n\n";
$products = odooRPC($url, 'object', 'execute_kw', [
    $db, $uid, $password,
    'product.product', 'search_read',
    [[]],
    ['fields' => ['name', 'default_code', 'qty_available', 'type'], 'limit' => 10]
]);

foreach ($products as $p) {
    echo "- {$p['name']} (Code: " . ($p['default_code'] ?: 'N/A') . ")\n";
    echo "  Type: {$p['type']}, Stock: {$p['qty_available']}\n\n";
}

echo "\n=== Getting Stock Quants ===\n\n";
$quants = odooRPC($url, 'object', 'execute_kw', [
    $db, $uid, $password,
    'stock.quant', 'search_read',
    [[]],
    ['fields' => ['product_id', 'location_id', 'quantity'], 'limit' => 10]
]);

echo "Total quants: " . count($quants) . "\n\n";
foreach ($quants as $q) {
    $product = is_array($q['product_id']) ? $q['product_id'][1] : $q['product_id'];
    $location = is_array($q['location_id']) ? $q['location_id'][1] : $q['location_id'];
    echo "- Product: $product\n";
    echo "  Location: $location\n";
    echo "  Quantity: {$q['quantity']}\n\n";
}
