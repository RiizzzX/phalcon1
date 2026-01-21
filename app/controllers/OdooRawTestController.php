<?php

class OdooRawTestController extends ControllerBase
{
    public function indexAction()
    {
        $url = 'http://odoo:8069';
        $db = 'coba_odoo';
        $username = 'farizlahya@gmail.com';
        $password = 'guwosari6b';
        
        // Test 1: Authenticate
        $authXml = '<?xml version="1.0"?><methodCall><methodName>authenticate</methodName><params><param><value><string>' . $db . '</string></value></param><param><value><string>' . $username . '</string></value></param><param><value><string>' . $password . '</string></value></param><param><value><array><data></data></array></value></param></params></methodCall>';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '/xmlrpc/2/common');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $authXml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        
        $authResponse = curl_exec($ch);
        curl_close($ch);
        
        $this->view->authRequest = htmlspecialchars($authXml);
        $this->view->authResponse = htmlspecialchars($authResponse);
        
        // Parse UID
        preg_match('/<int>(\d+)<\/int>/', $authResponse, $matches);
        $uid = $matches[1] ?? null;
        
        $this->view->uid = $uid;
        
        if ($uid) {
            // Test 2: Search equipment
            $searchXml = '<?xml version="1.0"?><methodCall><methodName>execute_kw</methodName><params><param><value><string>' . $db . '</string></value></param><param><value><int>' . $uid . '</int></value></param><param><value><string>' . $password . '</string></value></param><param><value><string>equipment.rental</string></value></param><param><value><string>search_read</string></value></param><param><value><array><data><value><array><data></data></array></value></data></array></value></param></params></methodCall>';
            
            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_URL, $url . '/xmlrpc/2/object');
            curl_setopt($ch2, CURLOPT_POST, 1);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $searchXml);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
            
            $searchResponse = curl_exec($ch2);
            curl_close($ch2);
            
            $this->view->searchRequest = htmlspecialchars($searchXml);
            $this->view->searchResponse = htmlspecialchars($searchResponse);
        }
    }
}
