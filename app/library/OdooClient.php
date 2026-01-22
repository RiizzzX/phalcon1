<?php

namespace App\Library;

/**
 * Odoo XML-RPC Client
 * Library untuk connect ke Odoo dari Phalcon menggunakan XML-RPC
 */
class OdooClient
{
    private $url;
    private $db;
    private $username;
    private $password;
    private $uid;

    public function __construct()
    {
        // Konfigurasi koneksi Odoo dari environment variables
        $this->url = getenv('ODOO_URL') ?: 'http://odoo:8069';
        $this->db = getenv('ODOO_DB') ?: 'test';
        $this->username = getenv('ODOO_USERNAME') ?: 'farizlahya@gmail.com';
        $this->password = getenv('ODOO_PASSWORD') ?: 'password';
    }

    /**
     * Build XML-RPC request manually
     */
    private function buildXmlRpcRequest($method, $params)
    {
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
        
        foreach ($params as $param) {
            $xml .= '<param>' . $this->valueToXml($param) . '</param>';
        }
        
        $xml .= '</params></methodCall>';
        return $xml;
    }

    /**
     * Convert PHP value to XML-RPC format
     */
    private function valueToXml($value)
    {
        if (is_array($value)) {
            // Check if associative array (struct) or indexed array
            if (array_keys($value) === range(0, count($value) - 1)) {
                // Indexed array
                $xml = '<value><array><data>';
                foreach ($value as $item) {
                    $xml .= $this->valueToXml($item);
                }
                $xml .= '</data></array></value>';
                return $xml;
            } else {
                // Associative array (struct)
                $xml = '<value><struct>';
                foreach ($value as $key => $val) {
                    $xml .= '<member><name>' . htmlspecialchars($key) . '</name>' . $this->valueToXml($val) . '</member>';
                }
                $xml .= '</struct></value>';
                return $xml;
            }
        } elseif (is_int($value)) {
            return '<value><int>' . $value . '</int></value>';
        } elseif (is_bool($value)) {
            return '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
        } elseif (is_double($value)) {
            return '<value><double>' . $value . '</double></value>';
        } else {
            return '<value><string>' . htmlspecialchars((string)$value) . '</string></value>';
        }
    }

    /**
     * Parse XML-RPC response
     */
    private function parseXmlRpcResponse($xml)
    {
        // Suppress warnings for malformed XML
        $prevErrorLevel = libxml_use_internal_errors(true);
        
        try {
            $xmlObj = simplexml_load_string($xml);
            
            if ($xmlObj === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                throw new \Exception('Failed to parse XML: ' . print_r($errors, true));
            }
            
            // Check for fault
            if (isset($xmlObj->fault)) {
                $fault = $this->parseValue($xmlObj->fault->value);
                throw new \Exception('XML-RPC Fault: ' . print_r($fault, true));
            }
            
            // Extract result from params/param/value
            if (isset($xmlObj->params->param->value)) {
                return $this->parseValue($xmlObj->params->param->value);
            }
            
            throw new \Exception('No value found in XML-RPC response');
            
        } finally {
            libxml_use_internal_errors($prevErrorLevel);
        }
    }
    
    /**
     * Parse XML-RPC value recursively
     */
    private function parseValue($value)
    {
        // Handle array
        if (isset($value->array)) {
            $result = [];
            if (isset($value->array->data)) {
                foreach ($value->array->data->value as $item) {
                    $result[] = $this->parseValue($item);
                }
            }
            return $result;
        }
        
        // Handle struct
        if (isset($value->struct)) {
            $result = [];
            foreach ($value->struct->member as $member) {
                $name = (string)$member->name;
                $result[$name] = $this->parseValue($member->value);
            }
            return $result;
        }
        
        // Handle scalar types
        if (isset($value->string)) return (string)$value->string;
        if (isset($value->int)) return (int)$value->int;
        if (isset($value->i4)) return (int)$value->i4;
        if (isset($value->double)) return (float)$value->double;
        if (isset($value->boolean)) return (bool)(int)$value->boolean;
        if (isset($value->dateTime)) return (string)$value->dateTime->iso8601;
        if (isset($value->base64)) return base64_decode((string)$value->base64);
        
        // Handle nil/null
        if (isset($value->nil)) return null;
        if (count($value->children()) === 0) return null;
        
        return null;
    }

    /**
     * Send XML-RPC request
     */
    private function xmlRpcCall($endpoint, $method, $params)
    {
        $xml = $this->buildXmlRpcRequest($method, $params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . $endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if (empty($response)) {
            throw new \Exception('Empty response from Odoo');
        }
        
        return $this->parseXmlRpcResponse($response);
    }

    /**
     * Authenticate
     */
    public function authenticate()
    {
        if ($this->uid) {
            return $this->uid;
        }

        $this->uid = $this->xmlRpcCall('/xmlrpc/2/common', 'authenticate', [
            $this->db,
            $this->username,
            $this->password,
            []
        ]);

        return $this->uid;
    }

    /**
     * Execute method
     */
    private function execute($model, $method, $args = [], $kwargs = [])
    {
        if (!$this->uid) {
            $this->authenticate();
        }

        $params = [
            $this->db,
            $this->uid,
            $this->password,
            $model,
            $method,
            $args
        ];
        
        // Add kwargs if provided
        if (!empty($kwargs)) {
            $params[] = $kwargs;
        }

        return $this->xmlRpcCall('/xmlrpc/2/object', 'execute_kw', $params);
    }
    
    /**
     * Execute method (public untuk testing)
     */
    public function executePublic($model, $method, $args = [], $kwargs = [])
    {
        return $this->execute($model, $method, $args, $kwargs);
    }

    /**
     * Get equipments
     */
    public function getEquipments($domain = [])
    {
        // Domain harus berupa array of tuples, contoh: [['status', '=', 'available']]
        // Jika kosong, ambil semua
        return $this->execute('equipment.rental', 'search_read', 
            [$domain],  // args: domain filter
            ['fields' => ['id', 'name', 'code', 'category', 'daily_rate', 'status', 'condition']] // kwargs
        );
    }

    /**
     * Get equipment by ID
     */
    public function getEquipment($id)
    {
        $result = $this->execute('equipment.rental', 'read', 
            [[$id]], // args: ids
            ['fields' => ['name', 'code', 'category', 'daily_rate', 'status', 'condition', 'description']] // kwargs
        );
        
        return $result ? $result[0] : null;
    }

    /**
     * Create equipment
     */
    public function createEquipment($data)
    {
        return $this->execute('equipment.rental', 'create', [[$data]]);
    }

    /**
     * Update equipment
     */
    public function updateEquipment($id, $data)
    {
        return $this->execute('equipment.rental', 'write', [[$id], $data]);
    }

    /**
     * Get rentals
     */
    public function getRentals($domain = [])
    {
        return $this->execute('rental.transaction', 'search_read',
            [$domain], // args: domain
            ['fields' => ['name', 'customer_name', 'customer_phone', 'equipment_id', 'rental_date', 'return_date', 'total_amount', 'state']] // kwargs
        );
    }

    /**
     * Create rental
     */
    public function createRental($data)
    {
        return $this->execute('rental.transaction', 'create', [[$data]]);
    }

    /**
     * Confirm rental
     */
    public function confirmRental($id)
    {
        return $this->execute('rental.transaction', 'action_confirm', [[$id]]);
    }

    /**
     * Return rental
     */
    public function returnRental($id)
    {
        return $this->execute('rental.transaction', 'action_return', [[$id]]);
    }

    /**
     * Get available equipments
     */
    public function getAvailableEquipments()
    {
        return $this->getEquipments([['status', '=', 'available']]);
    }

    /**
     * Delete equipment by ID (unlink in Odoo)
     */
    public function deleteEquipment($id)
    {
        return $this->execute('equipment.rental', 'unlink', [[$id]]);
    }
}
