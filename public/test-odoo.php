<?php
/**
 * Test koneksi Odoo - standalone script
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Koneksi Odoo</h1>";

// Konfigurasi
$url = 'http://odoo:8069';
$db = 'coba_odoo';
$username = 'farizlahya@gmail.com';
$password = 'guwosari6b';

echo "<h2>Konfigurasi:</h2>";
echo "<ul>";
echo "<li>URL: $url</li>";
echo "<li>Database: $db</li>";
echo "<li>Username: $username</li>";
echo "<li>Password: $password</li>";
echo "</ul>";

// Test 1: Cek koneksi ke Odoo
echo "<h2>Test 1: Ping Odoo</h2>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url . '/web/database/list');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "<p style='color:red'>❌ Gagal koneksi: $error</p>";
} else {
    echo "<p style='color:green'>✅ Berhasil koneksi ke Odoo</p>";
    echo "<p>Database list response: " . htmlspecialchars(substr($response, 0, 500)) . "</p>";
}

// Test 2: Authentication via XML-RPC
echo "<h2>Test 2: Authentication XML-RPC</h2>";

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

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url . '/xmlrpc/2/common');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "<p style='color:red'>❌ cURL Error: $error</p>";
} else {
    echo "<p>Raw Response:</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    
    // Parse UID
    if (preg_match('/<int>(\d+)<\/int>/', $response, $matches)) {
        $uid = $matches[1];
        echo "<p style='color:green'>✅ Authentication SUCCESS! UID = $uid</p>";
        
        // Test 3: Get equipments
        echo "<h2>Test 3: Get Equipments</h2>";
        
        $xml2 = '<?xml version="1.0"?>
<methodCall>
    <methodName>execute_kw</methodName>
    <params>
        <param><value><string>' . $db . '</string></value></param>
        <param><value><int>' . $uid . '</int></value></param>
        <param><value><string>' . $password . '</string></value></param>
        <param><value><string>equipment.rental</string></value></param>
        <param><value><string>search_read</string></value></param>
        <param><value><array><data>
            <value><array><data></data></array></value>
            <value><array><data>
                <value><string>name</string></value>
                <value><string>code</string></value>
                <value><string>status</string></value>
                <value><string>daily_rate</string></value>
            </data></array></value>
        </data></array></value></param>
    </params>
</methodCall>';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '/xmlrpc/2/object');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response2 = curl_exec($ch);
        $error2 = curl_error($ch);
        curl_close($ch);
        
        if ($error2) {
            echo "<p style='color:red'>❌ Error: $error2</p>";
        } else {
            echo "<p>Raw Response:</p>";
            echo "<pre>" . htmlspecialchars($response2) . "</pre>";
            
            if (strpos($response2, 'Canon') !== false) {
                echo "<p style='color:green'>✅ Equipment data ditemukan!</p>";
            } elseif (strpos($response2, 'faultString') !== false) {
                echo "<p style='color:orange'>⚠️ Ada error dari Odoo (mungkin model belum ter-install)</p>";
            } else {
                echo "<p style='color:orange'>⚠️ Tidak ada data equipment</p>";
            }
        }
        
    } elseif (strpos($response, '<boolean>0</boolean>') !== false || strpos($response, 'false') !== false) {
        echo "<p style='color:red'>❌ Authentication FAILED! Username/password salah atau database tidak ada</p>";
    } else {
        echo "<p style='color:orange'>⚠️ Response tidak dikenali</p>";
    }
}
?>
