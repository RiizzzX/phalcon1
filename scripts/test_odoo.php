<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/library/OdooClient.php';

$client = new \App\Library\OdooClient();
$result = $client->testConnection();
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);