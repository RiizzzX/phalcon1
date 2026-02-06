<?php
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/library/OdooClient.php';

header('Content-Type: application/json');
try {
    $client = new \App\Library\OdooClient();
    $result = $client->testConnection();
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
