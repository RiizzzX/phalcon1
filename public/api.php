<?php
/**
 * Unified API for Odoo Integration
 * Handles: Purchase Orders, Sales Orders, Invoices, Inventory, Customers
 * Supports: CRUD operations, status changes, validations, error handling
 */

use App\Library\OdooClient;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../vendor/autoload.php';
require_once __DIR__ . '/../app/library/OdooClient.php';

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Handle JSON input
    $jsonInput = json_decode(file_get_contents('php://input'), true);
    if ($jsonInput) {
        $_POST = array_merge($_POST, $jsonInput);
        $_REQUEST = array_merge($_REQUEST, $jsonInput);
    }

    $action = $_REQUEST['action'] ?? $_POST['action'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];
    
    if (!$action) {
        throw new Exception('Action parameter required');
    }

    $client = new \App\Library\OdooClient();

    // Standard DB connection for local database actions
    $getDb = function() {
        static $db = null;
        if ($db === null) {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
        return $db;
    };
    
    // ==================== PURCHASE ORDERS ====================
    
    if ($action === 'list_purchase_orders') {
        try {
            $orders = $client->executePublic('purchase.order', 'search_read',
                [[]],
                ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line', 'note']]
            );

            // Format partner_id
            foreach ($orders as &$order) {
                if (is_array($order['partner_id'])) {
                    $order['supplier_name'] = $order['partner_id'][1];
                    $order['supplier_id'] = $order['partner_id'][0];
                }
            }

            echo json_encode([
                'success' => true,
                'action' => 'list_purchase_orders',
                'count' => count($orders),
                'data' => $orders
            ]);
        } catch (Exception $e) {
            error_log('Error list_purchase_orders: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'action' => 'list_purchase_orders',
                'error' => 'Failed to fetch purchase orders: ' . $e->getMessage()
            ]);
        }

        exit;
    }

    if ($action === 'get_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        try {
            $order = $client->executePublic('purchase.order', 'read',
                [[$po_id]],
                ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line', 'note']]
            );
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'action' => 'get_purchase_order', 'error' => 'Odoo error: ' . $e->getMessage()]);
            exit;
        }

        if (!$order) {
            echo json_encode(['success' => false, 'action' => 'get_purchase_order', 'error' => 'Purchase Order not found']);
            exit;
        }

        $po = $order[0];
        
        // Get order lines
        if (!empty($po['order_line'])) {
            $lines = $client->executePublic('purchase.order.line', 'read',
                [$po['order_line']],
                ['fields' => ['id', 'product_id', 'name', 'product_qty', 'price_unit', 'price_subtotal']]
            );
            $po['lines'] = $lines;
        }

        echo json_encode([
            'success' => true,
            'action' => 'get_purchase_order',
            'data' => $po
        ]);
        exit;
    }

    if ($action === 'create_purchase_order') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $rawPartner = $data['partner_id'] ?? null;
        if (!$rawPartner) throw new Exception('Supplier (partner) ID required');

        $partnerIdToUse = null;
        // Support 'local-<id>' marker for locally created suppliers
        if (is_string($rawPartner) && str_starts_with($rawPartner, 'local-')) {
            $localId = (int)substr($rawPartner, 6);
            try {
                $db = $getDb();
                $stmt = $db->prepare('SELECT id, name, email FROM users WHERE id = :id');
                $stmt->execute([':id' => $localId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) throw new Exception('Local supplier not found');

                $partner_data = ['name' => $user['name'], 'email' => $user['email'], 'supplier_rank' => 1];
                $newPartnerId = $client->executePublic('res.partner', 'create', [$partner_data]);
                $partnerIdToUse = is_array($newPartnerId) ? $newPartnerId[0] : $newPartnerId;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Failed to create partner in Odoo: ' . $e->getMessage()]);
                exit;
            }
        } else {
            // numeric partner id expected (from Odoo)
            $partnerIdToUse = (int)$rawPartner;
        }

        // Map incoming notes -> Odoo 'note' (purchase.order expects 'note', not 'notes')
        $po_data = [
            'partner_id' => $partnerIdToUse,
            'date_order' => date('Y-m-d')
        ];
        if (!empty($data['notes'])) {
            $po_data['note'] = $data['notes'];
        }

        // Add order lines
        if (!empty($data['lines'])) {
            $po_data['order_line'] = [];
            foreach ($data['lines'] as $line) {
                $po_data['order_line'][] = [0, 0, [
                    'product_id' => (int)$line['product_id'],
                    'product_qty' => (float)$line['quantity'],
                    'price_unit' => (float)$line['price']
                ]];
            }
        }

        try {
            $po_id = $client->executePublic('purchase.order', 'create', [$po_data]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'action' => 'create_purchase_order',
                'error' => 'Odoo error: ' . $e->getMessage()
            ]);
            exit;
        }

        // Try to read the created PO so the UI can display it immediately
        $createdOrder = null;
        try {
            $orders = $client->executePublic('purchase.order', 'read', [[$po_id], ['id','name','partner_id','date_order','amount_total','state','order_line','note']]);
            if (!empty($orders)) $createdOrder = $orders[0];
        } catch (Exception $e) {
            // not critical, log and continue
            error_log('Warning: could not read created PO: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'action' => 'create_purchase_order',
            'message' => 'Purchase Order created',
            'po_id' => $po_id,
            'order' => $createdOrder
        ]);
        exit;
    }

    if ($action === 'update_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $update_data = [];
        if (isset($data['notes'])) $update_data['note'] = $data['notes'];
        if (isset($data['date_order'])) $update_data['date_order'] = $data['date_order'];

        $client->executePublic('purchase.order', 'write', [[$po_id], $update_data]);

        echo json_encode([
            'success' => true,
            'action' => 'update_purchase_order',
            'message' => 'Purchase Order updated'
        ]);
        exit;
    }

    if ($action === 'approve_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $client->executePublic('purchase.order', 'button_confirm', [[$po_id]]);

        // Adjust local inventory: add quantities from PO lines
        try {
            $db = $getDb();

            $lines = $client->executePublic('purchase.order.line', 'search_read', [[['order_id', '=', $po_id]]], ['fields' => ['product_id', 'product_qty']]);
            foreach ($lines as $ln) {
                $prod = $ln['product_id'] ?? null;
                $qty = isset($ln['product_qty']) ? (float)$ln['product_qty'] : 0;
                $odooProdId = is_array($prod) ? (int)$prod[0] : (int)$prod;
                if (!$odooProdId || $qty <= 0) continue;

                // Find local inventory by odoo_id
                $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE odoo_id = ? LIMIT 1');
                $stmt->execute([$odooProdId]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                // If not found try matching by product default_code or name
                if (!$inv) {
                    try {
                        $p = $client->executePublic('product.product', 'read', [[$odooProdId]], ['fields' => ['id','name','default_code']]);
                        $code = $p[0]['default_code'] ?? null;
                        $name = $p[0]['name'] ?? null;
                        if ($code) {
                            $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE sku = ? LIMIT 1');
                            $stmt->execute([$code]);
                            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$inv && $name) {
                            $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE name = ? LIMIT 1');
                            $stmt->execute([$name]);
                            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                    } catch (Exception $e) {}
                }

                if ($inv) {
                    $newQty = (float)$inv['quantity'] + $qty;
                    $u = $db->prepare('UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?');
                    $u->execute([$newQty, $inv['id']]);
                }
            }
        } catch (Exception $e) {
            error_log('Warning: failed to update inventory after PO confirm: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'action' => 'approve_purchase_order',
            'message' => 'Purchase Order approved',
            'new_state' => 'purchase'
        ]);
        exit;
    }

    if ($action === 'reject_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $client->executePublic('purchase.order', 'button_cancel', [[$po_id]]);

        echo json_encode([
            'success' => true,
            'action' => 'reject_purchase_order',
            'message' => 'Purchase Order cancelled',
            'new_state' => 'cancel'
        ]);
        exit;
    }

    if ($action === 'delete_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $client->executePublic('purchase.order', 'unlink', [[$po_id]]);

        echo json_encode([
            'success' => true,
            'action' => 'delete_purchase_order',
            'message' => 'Purchase Order deleted'
        ]);
        exit;
    }

    if ($action === 'delete_sales_order') {
        $so_id = (int)($_REQUEST['id'] ?? 0);
        if (!$so_id) throw new Exception('Sales Order ID required');

        // Read current state and lines
        try {
            $so = $client->executePublic('sale.order', 'read', [[$so_id]], ['fields' => ['id','state','order_line']]);
            if (empty($so)) throw new Exception('Sales Order not found');
            $so = $so[0];
            $state = $so['state'] ?? 'draft';

            // If confirmed or sent, cancel first and restore inventory quantities
            if (in_array($state, ['sale','sent'])) {
                // Read lines with quantities
                $lines = $client->executePublic('sale.order.line', 'search_read', [[['order_id', '=', $so_id]]], ['fields' => ['product_id','product_uom_qty']]);

                // Restore local inventory quantities (add back)
                try {
                    $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
                    $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
                    $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
                    $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
                    $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                    foreach ($lines as $ln) {
                        $prod = $ln['product_id'] ?? null;
                        $qty = isset($ln['product_uom_qty']) ? (float)$ln['product_uom_qty'] : 0;
                        $odooProdId = is_array($prod) ? (int)$prod[0] : (int)$prod;
                        if (!$odooProdId || $qty <= 0) continue;

                        // Find local inventory by odoo_id, sku or name
                        $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE odoo_id = ? LIMIT 1');
                        $stmt->execute([$odooProdId]);
                        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$inv) {
                            try {
                                $p = $client->executePublic('product.product', 'read', [[$odooProdId]], ['fields' => ['id','name','default_code']]);
                                $code = $p[0]['default_code'] ?? null;
                                $name = $p[0]['name'] ?? null;
                                if ($code) {
                                    $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE sku = ? LIMIT 1');
                                    $stmt->execute([$code]);
                                    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                                }
                                if (!$inv && $name) {
                                    $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE name = ? LIMIT 1');
                                    $stmt->execute([$name]);
                                    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                                }
                            } catch (Exception $e) {}
                        }

                        if ($inv) {
                            $newQty = (float)$inv['quantity'] + $qty;
                            $u = $db->prepare('UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?');
                            $u->execute([$newQty, $inv['id']]);
                        }
                    }
                } catch (Exception $e) {
                    error_log('Warning: failed to restore inventory before deleting SO: ' . $e->getMessage());
                }

                // Cancel the sales order first
                try {
                    $client->executePublic('sale.order', 'action_cancel', [[$so_id]]);
                } catch (Exception $e) {
                    // If cancel fails, surface the message
                    throw new Exception('Failed to cancel sales order before delete: ' . $e->getMessage());
                }
            }

            // After cancellation (or if already canceled/draft), unlink
            $client->executePublic('sale.order', 'unlink', [[$so_id]]);

            echo json_encode([
                'success' => true,
                'action' => 'delete_sales_order',
                'message' => 'Sales Order deleted'
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'action' => 'delete_sales_order',
                'error' => 'Failed to delete sales order: ' . $e->getMessage()
            ]);
            exit;
        }
    }

    // ==================== SALES ORDERS ====================

    if ($action === 'list_sales_orders') {
        $orders = $client->executePublic('sale.order', 'search_read',
            [[]],
            ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line']]
        );

        foreach ($orders as &$order) {
            if (is_array($order['partner_id'])) {
                $order['customer_name'] = $order['partner_id'][1];
                $order['customer_id'] = $order['partner_id'][0];
            }
        }

        echo json_encode([
            'success' => true,
            'action' => 'list_sales_orders',
            'count' => count($orders),
            'data' => $orders
        ]);
        exit;
    }

    if ($action === 'create_sales_order') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $customer_raw = $data['customer_id'] ?? null;

        // Support local customers (ids like 'local-<id>') by mapping/creating partner in Odoo
        $customer_id = 0;
        if (is_string($customer_raw) && preg_match('/^local-(\d+)$/', $customer_raw, $m)) {
            $localId = (int)$m[1];
            // Lookup local user and create partner in Odoo
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$localId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception('Local customer not found: ' . $localId);

            $partnerData = [
                'name' => $user['name'],
                'customer_rank' => 1
            ];
            if (!empty($user['email'])) $partnerData['email'] = $user['email'];
            if (!empty($user['phone'])) $partnerData['phone'] = $user['phone'];

            try {
                $partnerId = $client->executePublic('res.partner', 'create', [$partnerData]);
                $partnerId = is_array($partnerId) ? $partnerId[0] : $partnerId;
                $customer_id = (int)$partnerId;
            } catch (Exception $e) {
                throw new Exception('Failed to create partner in Odoo: ' . $e->getMessage());
            }
        } else {
            $customer_id = (int)$customer_raw;
        }

        if (!$customer_id) throw new Exception('Customer ID required');

        // Prepare DB connection for mapping inventory -> Odoo
        $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
        $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
        $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
        $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $so_data = [
            'partner_id' => $customer_id,
            'date_order' => date('Y-m-d H:i:s')
        ];

        if (!empty($data['lines'])) {
            $so_data['order_line'] = [];
            foreach ($data['lines'] as $line) {
                $rawPid = $line['product_id'];
                $odooProductId = null;

                // If product comes from local inventory (inventory-<id>) -> map or create in Odoo
                if (is_string($rawPid) && preg_match('/^inventory-(\d+)$/', $rawPid, $m)) {
                    $invId = (int)$m[1];
                    $stmt = $db->prepare('SELECT * FROM inventory WHERE id = ? LIMIT 1');
                    $stmt->execute([$invId]);
                    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$inv) throw new Exception('Inventory product not found: ' . $invId);

                    // Try to find product in Odoo by default_code (sku) or name
                    $found = null;
                    if (!empty($inv['sku'])) {
                        $found = $client->executePublic('product.product', 'search_read', [[['default_code', '=', $inv['sku']]],], ['fields' => ['id']]);
                    }
                    if (empty($found)) {
                        $found = $client->executePublic('product.product', 'search_read', [[['name', '=', $inv['name']]],], ['fields' => ['id']]);
                    }
                    if (!empty($found) && is_array($found) && count($found) > 0) {
                        $odooProductId = $found[0]['id'];
                    } else {
                        // Create product in Odoo
                        $prodData = [
                            'name' => $inv['name'],
                            'list_price' => (float)($inv['selling_price'] ?? 0),
                            'standard_price' => (float)($inv['cost_price'] ?? 0),
                            'default_code' => $inv['sku'] ?? null,
                            'type' => $inv['product_type'] ?? 'product'
                        ];
                        $odooProductId = $client->executePublic('product.product', 'create', [$prodData]);

                        // Save mapping back to local inventory (best-effort)
                        try {
                            $u = $db->prepare('UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1 WHERE id = ?');
                            $u->execute([$odooProductId, $invId]);
                        } catch (Exception $e) {
                            error_log('Warning: failed to update inventory odoo_id: ' . $e->getMessage());
                        }
                    }
                } else {
                    // Normal numeric Odoo product id
                    $odooProductId = (int)$rawPid;
                }

                if (!$odooProductId) throw new Exception('Unable to resolve product for line');

                $so_data['order_line'][] = [0, 0, [
                    'product_id' => $odooProductId,
                    'product_uom_qty' => (float)$line['quantity'],
                    'price_unit' => (float)$line['price']
                ]];
            }
        }

        $so_id = $client->executePublic('sale.order', 'create', [$so_data]);

        echo json_encode([
            'success' => true,
            'action' => 'create_sales_order',
            'message' => 'Sales Order created',
            'so_id' => $so_id
        ]);
        exit;
    }

    if ($action === 'confirm_sales_order') {
        $so_id = (int)($_REQUEST['id'] ?? 0);
        if (!$so_id) throw new Exception('Sales Order ID required');

        $client->executePublic('sale.order', 'action_confirm', [[$so_id]]);

        // Adjust local inventory: subtract quantities from SO lines
        try {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $lines = $client->executePublic('sale.order.line', 'search_read', [[['order_id', '=', $so_id]]], ['fields' => ['product_id', 'product_uom_qty']]);
            foreach ($lines as $ln) {
                $prod = $ln['product_id'] ?? null;
                $qty = isset($ln['product_uom_qty']) ? (float)$ln['product_uom_qty'] : 0;
                $odooProdId = is_array($prod) ? (int)$prod[0] : (int)$prod;
                if (!$odooProdId || $qty <= 0) continue;

                // Find local inventory by odoo_id
                $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE odoo_id = ? LIMIT 1');
                $stmt->execute([$odooProdId]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                // If not found try matching by product default_code or name
                if (!$inv) {
                    try {
                        $p = $client->executePublic('product.product', 'read', [[$odooProdId]], ['fields' => ['id','name','default_code']]);
                        $code = $p[0]['default_code'] ?? null;
                        $name = $p[0]['name'] ?? null;
                        if ($code) {
                            $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE sku = ? LIMIT 1');
                            $stmt->execute([$code]);
                            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$inv && $name) {
                            $stmt = $db->prepare('SELECT id, quantity FROM inventory WHERE name = ? LIMIT 1');
                            $stmt->execute([$name]);
                            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                    } catch (Exception $e) {}
                }

                if ($inv) {
                    $newQty = max(0, (float)$inv['quantity'] - $qty);
                    $u = $db->prepare('UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?');
                    $u->execute([$newQty, $inv['id']]);
                }
            }
        } catch (Exception $e) {
            error_log('Warning: failed to update inventory after SO confirm: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'action' => 'confirm_sales_order',
            'message' => 'Sales Order confirmed',
            'new_state' => 'sale'
        ]);
        exit;
    }

    if ($action === 'cancel_sales_order') {
        $so_id = (int)($_REQUEST['id'] ?? 0);
        if (!$so_id) throw new Exception('Sales Order ID required');

        $client->executePublic('sale.order', 'action_cancel', [[$so_id]]);

        echo json_encode([
            'success' => true,
            'action' => 'cancel_sales_order',
            'message' => 'Sales Order cancelled',
            'new_state' => 'cancel'
        ]);
        exit;
    }

    // ==================== INVOICES ====================

    if ($action === 'list_invoices') {
        $invoices = $client->executePublic('account.move', 'search_read',
            [[['move_type', 'in', ['in_invoice', 'out_invoice']]]],
            ['fields' => ['id', 'name', 'partner_id', 'invoice_date', 'amount_total', 'state', 'move_type']]
        );

        echo json_encode([
            'success' => true,
            'action' => 'list_invoices',
            'count' => count($invoices),
            'data' => $invoices
        ]);
        exit;
    }

    // Duplicate "get_invoice" handler removed. Use the comprehensive handler later in the file that returns both invoice and its lines.

    // Create invoice via API (AJAX friendly)
    if ($action === 'create_invoice') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $partnerId = $data['partner_id'] ?? null;
        $productId = $data['product_id'] ?? null;
        $quantity = isset($data['quantity']) ? (float)$data['quantity'] : 1;
        $price = isset($data['price']) ? (float)$data['price'] : 0.0;
        $productName = $data['product_name'] ?? 'Product';
        $moveType = $data['move_type'] ?? 'out_invoice';
        $orderId = $data['order_id'] ?? null;

        if (!$partnerId) throw new Exception('partner_id required');
        
        try {
            // In Odoo, it's better to create lines together with the move
            $line = [
                'name' => $productName,
                'quantity' => $quantity,
                'price_unit' => $price,
            ];
            
            if ($productId && (int)$productId > 0) {
                $line['product_id'] = (int)$productId;
            }

            // Create invoice with lines in one go (Command 0 = Create)
            $invoice = [
                'partner_id' => (int)$partnerId,
                'move_type' => $moveType,
                'invoice_date' => date('Y-m-d'),
                'invoice_line_ids' => [
                    [0, 0, $line]
                ]
            ];
            
            if ($orderId) {
                $prefix = ($moveType === 'out_invoice') ? 'SO' : 'PO';
                $invoice['invoice_origin'] = $prefix . $orderId;
            }

            $invoiceId = $client->executePublic('account.move', 'create', [$invoice]);
            if (is_array($invoiceId)) $invoiceId = $invoiceId[0];

            // Optionally post the invoice immediately (so it's usable in Odoo workflows)
            $posted = false;
            if (!empty($data['post_now']) && $invoiceId) {
                try {
                    $client->executePublic('account.move', 'action_post', [[$invoiceId]]);
                    $posted = true;
                } catch (Exception $e) {
                    error_log('Warning: failed to post invoice '.$invoiceId.': '.$e->getMessage());
                    $posted = false;
                }
            }

            // Return invoice id and whether it was posted
            echo json_encode([
                'success' => true, 
                'action' => 'create_invoice', 
                'message' => 'Invoice created successfully', 
                'invoice_id' => $invoiceId,
                'posted' => $posted
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Odoo Create Invoice Error: " . $e->getMessage());
            echo json_encode([
                'success' => false, 
                'action' => 'create_invoice', 
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    if ($action === 'get_partner_orders') {
        $partnerId = (int)($_REQUEST['partner_id'] ?? 0);
        if (!$partnerId) throw new Exception('Partner ID required');

        // Fetch Sales Orders for this partner
        $sales = $client->executePublic('sale.order', 'search_read',
            [[['partner_id', '=', $partnerId], ['state', '=', 'sale']]],
            ['fields' => ['id', 'name', 'amount_total', 'date_order', 'order_line']]
        );

        // Fetch Purchase Orders for this partner
        $purchases = $client->executePublic('purchase.order', 'search_read',
            [[['partner_id', '=', $partnerId], ['state', '=', 'purchase']]],
            ['fields' => ['id', 'name', 'amount_total', 'date_order', 'order_line']]
        );

        echo json_encode([
            'success' => true,
            'action' => 'get_partner_orders',
            'data' => [
                'sales' => $sales,
                'purchases' => $purchases
            ]
        ]);
        exit;
    }

    if ($action === 'post_invoice') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $inv_id = (int)($data['id'] ?? 0);
        if (!$inv_id) throw new Exception('Invoice ID required');

        try {
            $client->executePublic('account.move', 'action_post', [[$inv_id]]);
            echo json_encode(['success' => true, 'action' => 'post_invoice', 'message' => 'Invoice posted']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'register_payment') {
        // This endpoint has been removed. Use Odoo UI or a dedicated accounting workflow to register payments.
        echo json_encode(['success' => false, 'action' => 'register_payment', 'error' => 'register_payment action removed from public API']);
        exit;
    }

    // Get a single invoice with lines (AJAX)
    if ($action === 'get_invoice') {
        $inv_id = (int)($_REQUEST['id'] ?? 0);
        if (!$inv_id) throw new Exception('Invoice ID required');

        $invoice = $client->executePublic('account.move', 'read', [[$inv_id], ['id','name','partner_id','invoice_date','invoice_date_due','amount_total','state','move_type','invoice_line_ids','narration']]);
        if (empty($invoice)) throw new Exception('Invoice not found');
        $invoice = $invoice[0];

        $lines = [];
        if (!empty($invoice['invoice_line_ids'])) {
            $lines = $client->executePublic('account.move.line', 'read', [$invoice['invoice_line_ids'], ['product_id','name','quantity','price_unit','price_subtotal']]);
        }

        echo json_encode([
            'success' => true,
            'action' => 'get_invoice',
            'data' => ['invoice' => $invoice, 'lines' => $lines]
        ]);
        exit;
    }

    // Delete invoice (tries direct unlink, if fails, attempts draft+unlink)
    if ($action === 'delete_invoice') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $inv_id = (int)($data['id'] ?? $_REQUEST['id'] ?? 0);
        if (!$inv_id) throw new Exception('Invoice ID required');

        try {
            // First attempt: direct unlink (best-case)
            $client->executePublic('account.move', 'unlink', [[$inv_id]]);
            echo json_encode(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice deleted']);
            exit;
        } catch (Exception $e) {
            // Collect errors to return helpful message
            $errors = [];
            $errors[] = 'unlink: ' . $e->getMessage();
            error_log('Delete invoice initial unlink failed for '.$inv_id.': '.$e->getMessage());

            // Attempt 1: move to draft then unlink
            try {
                $client->executePublic('account.move', 'button_draft', [[$inv_id]]);
                $client->executePublic('account.move', 'unlink', [[$inv_id]]);
                echo json_encode(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice moved to draft and deleted']);
                exit;
            } catch (Exception $e2) {
                $errors[] = 'button_draft+unlink: ' . $e2->getMessage();
                error_log('Delete invoice button_draft+unlink failed for '.$inv_id.': '.$e2->getMessage());
            }

            // Attempt 2: try cancel/action_cancel then draft then unlink
            try {
                $client->executePublic('account.move', 'action_cancel', [[$inv_id]]);
                $client->executePublic('account.move', 'button_draft', [[$inv_id]]);
                $client->executePublic('account.move', 'unlink', [[$inv_id]]);
                echo json_encode(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice canceled, moved to draft and deleted']);
                exit;
            } catch (Exception $e3) {
                $errors[] = 'action_cancel+...: ' . $e3->getMessage();
                error_log('Delete invoice action_cancel flow failed for '.$inv_id.': '.$e3->getMessage());
            }

            // Attempt 3: try writing state to draft (best-effort) then unlink
            try {
                $client->executePublic('account.move', 'write', [[$inv_id], ['state' => 'draft']]);
                $client->executePublic('account.move', 'unlink', [[$inv_id]]);
                echo json_encode(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice forced to draft and deleted']);
                exit;
            } catch (Exception $e4) {
                $errors[] = 'write+unlink: ' . $e4->getMessage();
                error_log('Delete invoice write+unlink failed for '.$inv_id.': '.$e4->getMessage());
            }

            // If all attempts failed, return combined errors
            echo json_encode(['success' => false, 'action' => 'delete_invoice', 'error' => implode(' | ', $errors)]);
            exit;
        }
    }

    // New action to get order lines to populate invoice
    if ($action === 'get_order_details') {
        $orderId = (int)($_REQUEST['id'] ?? 0);
        $type = $_REQUEST['type'] ?? 'sale'; // 'sale' or 'purchase'
        if (!$orderId) throw new Exception('Order ID required');

        $model = ($type === 'sale') ? 'sale.order.line' : 'purchase.order.line';
        $domain = ($type === 'sale') ? [['order_id', '=', $orderId]] : [['order_id', '=', $orderId]];
        
        $lines = $client->executePublic($model, 'search_read',
            [$domain],
            ['fields' => ['product_id', 'product_uom_qty', 'qty_received', 'qty_invoiced', 'price_unit', 'name']]
        );

        echo json_encode([
            'success' => true,
            'action' => 'get_order_details',
            'data' => $lines
        ]);
        exit;
    }

    // ==================== PRODUCTS ====================

    if ($action === 'list_products') {
        $products = $client->executePublic('product.product', 'search_read',
            [[]],
            ['fields' => ['id', 'name', 'list_price', 'standard_price', 'qty_available', 'categ_id']]
        );

        echo json_encode([
            'success' => true,
            'action' => 'list_products',
            'count' => count($products),
            'data' => $products
        ]);
        exit;
    }

    if ($action === 'create_product') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $product_data = [
            'name' => $data['name'] ?? throw new Exception('Product name required'),
            'list_price' => (float)($data['price'] ?? 0),
            'standard_price' => (float)($data['cost'] ?? 0),
            'type' => $data['type'] ?? 'product'
        ];

        if (isset($data['category_id'])) {
            $product_data['categ_id'] = (int)$data['category_id'];
        }

        $product_id = $client->executePublic('product.product', 'create', [$product_data]);

        echo json_encode([
            'success' => true,
            'action' => 'create_product',
            'message' => 'Product created',
            'product_id' => $product_id
        ]);
        exit;
    }

    // ==================== PARTNERS (Customers/Suppliers) ====================

    if ($action === 'list_partners') {
        $partners = $client->executePublic('res.partner', 'search_read',
            [[]],
            ['fields' => ['id', 'name', 'email', 'phone', 'street', 'city', 'country_id', 'supplier_rank', 'customer_rank']]
        );

        echo json_encode([
            'success' => true,
            'action' => 'list_partners',
            'count' => count($partners),
            'data' => $partners
        ]);
        exit;
    }

    // Unified: create a partner (can be customer and/or supplier)
    if ($action === 'create_partner') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $is_customer = !empty($data['is_customer']) ? 1 : 0;
        $is_supplier = !empty($data['is_supplier']) ? 1 : 0;
        $saveTo = trim($data['save_to'] ?? ($data['saveTo'] ?? 'odoo'));

        if (!$name) throw new Exception('Name required');

        // If saving to Odoo, try to find existing partner by email or name and update ranks
        $partner_id = null;
        if (strtolower($saveTo) === 'odoo') {
            try {
                if ($email) {
                    $found = $client->executePublic('res.partner', 'search_read', [[['email', '=', $email]]], ['fields' => ['id','name','email','customer_rank','supplier_rank']]);
                    if (!empty($found)) $partner_id = $found[0]['id'];
                }
                if (!$partner_id) {
                    $found = $client->executePublic('res.partner', 'search_read', [[['name', '=', $name]]], ['fields' => ['id','name','customer_rank','supplier_rank']]);
                    if (!empty($found)) $partner_id = $found[0]['id'];
                }
            } catch (Exception $e) {
                error_log('Warning: create_partner Odoo lookup failed: ' . $e->getMessage());
            }

            if ($partner_id) {
                try {
                    $update = [];
                    if ($is_customer) $update['customer_rank'] = 1; else $update['customer_rank'] = 0;
                    if ($is_supplier) $update['supplier_rank'] = 1; else $update['supplier_rank'] = 0;
                    $client->executePublic('res.partner', 'write', [[$partner_id], $update]);
                } catch (Exception $e) {
                    error_log('Warning: create_partner Odoo write failed: ' . $e->getMessage());
                }
            } else {
                try {
                    $partner_data = ['name' => $name, 'email' => $email, 'phone' => $phone];
                    if ($is_customer) $partner_data['customer_rank'] = 1;
                    if ($is_supplier) $partner_data['supplier_rank'] = 1;
                    $partner_id = $client->executePublic('res.partner', 'create', [$partner_data]);
                    $partner_id = is_array($partner_id) ? $partner_id[0] : $partner_id;
                } catch (Exception $e) {
                    error_log('Warning: create_partner Odoo create failed: ' . $e->getMessage());
                    $partner_id = null;
                }
            }
        }

        // Save locally (always) and record flags if table supports them
        try {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Ensure columns exist
            $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
            $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
            if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
            if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

            if ($haveCustomer || $haveSupplier) {
                $stmt = $db->prepare('INSERT INTO users (name, email, phone, is_customer, is_supplier) VALUES (:name, :email, :phone, :c, :s)');
                $stmt->execute([':name'=>$name,':email'=>$email,':phone'=>$phone,':c'=>$is_customer,':s'=>$is_supplier]);
            } else {
                $stmt = $db->prepare('INSERT INTO users (name, email, phone) VALUES (:name, :email, :phone)');
                $stmt->execute([':name'=>$name,':email'=>$email,':phone'=>$phone]);
            }
            $localId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log('Warning: create_partner local save failed: '.$e->getMessage());
            $localId = null;
        }

        echo json_encode(['success'=>true,'action'=>'create_partner','message'=>'Partner created','id'=>$localId,'partner_id'=>$partner_id,'name'=>$name,'is_customer'=>$is_customer,'is_supplier'=>$is_supplier]);
        exit;
    }

    // Update partner role flags (customer/supplier)
    if ($action === 'update_partner_roles') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $rawId = $data['id'] ?? $data['partner_id'] ?? $_REQUEST['id'] ?? null;
        if (!$rawId) throw new Exception('Partner id required');
        $is_customer = !empty($data['is_customer']) ? 1 : 0;
        $is_supplier = !empty($data['is_supplier']) ? 1 : 0;

        // Local record
        if (is_string($rawId) && preg_match('/^local-(\d+)$/', $rawId, $m)) {
            $localId = (int)$m[1];
            try {
                $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
                $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
                $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
                $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
                $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                // Ensure columns exist
                $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
                $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
                if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
                if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

                $u = $db->prepare('UPDATE users SET is_customer = ?, is_supplier = ? WHERE id = ?');
                $u->execute([$is_customer, $is_supplier, $localId]);

                echo json_encode(['success'=>true,'action'=>'update_partner_roles','message'=>'Local partner roles updated']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to update local partner: ' . $e->getMessage());
            }
        }

        // Odoo partner
        $partnerId = (int)$rawId;
        if (!$partnerId) throw new Exception('Invalid partner id');
        try {
            $update = ['customer_rank' => $is_customer, 'supplier_rank' => $is_supplier];
            $client->executePublic('res.partner', 'write', [[$partnerId], $update]);
            echo json_encode(['success'=>true,'action'=>'update_partner_roles','message'=>'Partner roles updated in Odoo']);
            exit;
        } catch (Exception $e) {
            throw new Exception('Failed to update partner in Odoo: ' . $e->getMessage());
        }
    }

    if ($action === 'list_suppliers') {
        $suppliers = [];
        // Try to fetch suppliers from Odoo first (best-effort)
        try {
            $odooSuppliers = $client->executePublic('res.partner', 'search_read',
                [[['supplier_rank', '>', 0]]],
                ['fields' => ['id', 'name', 'email', 'phone']]
            );
            if (is_array($odooSuppliers)) $suppliers = $odooSuppliers;
        } catch (Exception $e) {
            // log and continue — we'll still include local suppliers
            error_log('Warning: list_suppliers Odoo fetch failed: ' . $e->getMessage());
        }

        // Append local users (so suppliers created locally appear in lists)
        try {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $db->query("SELECT id, name, email, phone FROM users ORDER BY id DESC");
            $localUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($localUsers as $u) {
                // mark local suppliers with id prefix so caller can distinguish
                $suppliers[] = [
                    'id' => 'local-' . $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'phone' => $u['phone'] ?? null,
                    'local' => true
                ];
            }
        } catch (Exception $e) {
            // ignore DB errors
            error_log('Warning: list_suppliers local read failed: ' . $e->getMessage());
        }

        // Deduplicate: prefer Odoo supplier when email matches, otherwise unique by name
        $seen = [];
        $dedup = [];
        foreach ($suppliers as $s) {
            $key = '';
            if (!empty($s['email'])) $key = 'e:' . strtolower($s['email']);
            else $key = 'n:' . strtolower(trim($s['name'] ?? ''));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $dedup[] = $s;
        }

        echo json_encode([
            'success' => true,
            'action' => 'list_suppliers',
            'count' => count($dedup),
            'data' => $dedup
        ]);
        exit;
    }

    if ($action === 'list_customers') {
        $customers = [];
        try {
            $odooCustomers = $client->executePublic('res.partner', 'search_read',
                [[['customer_rank', '>', 0]]],
                ['fields' => ['id', 'name', 'email', 'phone', 'city']]
            );
            if (is_array($odooCustomers)) $customers = $odooCustomers;
        } catch (Exception $e) {
            error_log('Warning: list_customers Odoo fetch failed: ' . $e->getMessage());
        }

        // Append local users as customers (id prefixed with local-)
        try {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $db->query("SELECT id, name, email FROM users ORDER BY id DESC");
            $localUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($localUsers as $u) {
                $customers[] = [
                    'id' => 'local-' . $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'local' => true
                ];
            }
        } catch (Exception $e) {
            error_log('Warning: list_customers local read failed: ' . $e->getMessage());
        }

        // Deduplicate customers: prefer Odoo entries, then local ones not already present
        $seen = [];
        $dedup = [];
        foreach ($customers as $c) {
            $key = '';
            if (!empty($c['email'])) $key = 'e:' . strtolower($c['email']);
            else $key = 'n:' . strtolower(trim($c['name'] ?? '')) . '|' . ($c['phone'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $dedup[] = $c;
        }

        echo json_encode([
            'success' => true,
            'action' => 'list_customers',
            'count' => count($dedup),
            'data' => $dedup
        ]);
        exit;
    }

    if ($action === 'create_partner') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $partner_data = [
            'name' => $data['name'] ?? throw new Exception('Partner name required'),
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'is_company' => (bool)($data['is_company'] ?? true)
        ];

        if (isset($data['supplier'])) $partner_data['supplier_rank'] = (int)$data['supplier'];
        if (isset($data['customer'])) $partner_data['customer_rank'] = (int)$data['customer'];

        $partner_id = $client->executePublic('res.partner', 'create', [$partner_data]);

        echo json_encode([
            'success' => true,
            'action' => 'create_partner',
            'message' => 'Partner created',
            'partner_id' => $partner_id
        ]);
        exit;
    }

    // Create customer (supports save_to = 'local' or 'odoo')
    if ($action === 'create_customer') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $saveTo = trim($data['save_to'] ?? ($data['saveTo'] ?? 'odoo'));

        if (!$name) throw new Exception('Name required');

        $partner_id = null;
        if (strtolower($saveTo) === 'odoo') {
            $partner_data = [
                'name' => $name,
                'customer_rank' => 1
            ];
            if ($email) $partner_data['email'] = $email;
            if ($phone) $partner_data['phone'] = $phone;

            try {
                $partner_id = $client->executePublic('res.partner', 'create', [$partner_data]);
                $partner_id = is_array($partner_id) ? $partner_id[0] : $partner_id;
            } catch (Exception $e) {
                error_log('Warning: create_customer Odoo create failed: ' . $e->getMessage());
                $partner_id = null;
            }
        }

        try {
            $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
            $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
            $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
            $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');

            $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            // Some installations may not have phone column in users table. Detect and adapt.
            $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetchAll();
            if (count($colCheck) > 0) {
                $stmt = $db->prepare("INSERT INTO users (name, email, phone) VALUES (:name, :email, :phone)");
                $stmt->execute([':name' => $name, ':email' => $email, ':phone' => $phone]);
            } else {
                $stmt = $db->prepare("INSERT INTO users (name, email) VALUES (:name, :email)");
                $stmt->execute([':name' => $name, ':email' => $email]);
            }
            $localId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception('Failed to save customer locally: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'action' => 'create_customer',
            'message' => 'Customer created locally',
            'id' => $localId,
            'name' => $name,
            'partner_id' => $partner_id
        ]);
        exit;
    }

    if ($action === 'delete_customer') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $rawId = $data['id'] ?? $_REQUEST['id'] ?? null;
        if (!$rawId) throw new Exception('Customer ID required');

        // Delete local customer
        if (is_string($rawId) && preg_match('/^local-(\d+)$/', $rawId, $m)) {
            $localId = (int)$m[1];
            try {
                $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
                $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
                $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
                $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
                $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$localId]);

                echo json_encode(['success' => true, 'action' => 'delete_customer', 'message' => 'Local customer deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete local customer: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid customer id');
            try {
                $client->executePublic('res.partner', 'unlink', [[$partnerId]]);
                echo json_encode(['success' => true, 'action' => 'delete_customer', 'message' => 'Odoo partner deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete Odoo partner: ' . $e->getMessage());
            }
        }
    }

    // Helper: create partner in Odoo and save as local supplier in users table
    if ($action === 'create_supplier') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $saveTo = trim($data['save_to'] ?? ($data['saveTo'] ?? 'odoo'));

        if (!$name) throw new Exception('Name required');

        $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
        $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
        $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
        $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Deduplicate local by email or name+phone
        try {
            $existing = null;
            if ($email) {
                $stmt = $db->prepare('SELECT id, name, email, phone FROM users WHERE LOWER(email) = ? LIMIT 1');
                $stmt->execute([strtolower($email)]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (empty($existing) && $phone) {
                $stmt = $db->prepare('SELECT id, name, email, phone FROM users WHERE name = ? AND phone = ? LIMIT 1');
                $stmt->execute([$name, $phone]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!empty($existing)) {
                echo json_encode(['success' => true, 'action' => 'create_supplier', 'message' => 'Supplier already exists locally', 'id' => (int)$existing['id'], 'name' => $existing['name'], 'partner_id' => null]);
                exit;
            }
        } catch (Exception $e) {
            error_log('Warning: create_supplier local lookup failed: ' . $e->getMessage());
        }

        // Optionally create or find in Odoo
        $partner_id = null;
        if (strtolower($saveTo) === 'odoo') {
            try {
                if ($email) {
                    $found = $client->executePublic('res.partner', 'search_read', [[['email', '=', $email]]], ['fields' => ['id','name','email']]);
                    if (!empty($found)) $partner_id = $found[0]['id'];
                }
                if (!$partner_id) {
                    $found = $client->executePublic('res.partner', 'search_read', [[['name', '=', $name]]], ['fields' => ['id','name']]);
                    if (!empty($found)) $partner_id = $found[0]['id'];
                }
            } catch (Exception $e) {
                error_log('Warning: create_supplier Odoo lookup failed: ' . $e->getMessage());
            }

            if (!$partner_id) {
                $partner_data = ['name' => $name, 'email' => $email, 'phone' => $phone, 'is_company' => true, 'supplier_rank' => 1];
                try {
                    $partner_id = $client->executePublic('res.partner', 'create', [$partner_data]);
                    $partner_id = is_array($partner_id) ? $partner_id[0] : $partner_id;
                } catch (Exception $e) {
                    error_log('Warning: create_supplier Odoo create failed: ' . $e->getMessage());
                    $partner_id = null;
                }
            }
        }

        // Save local record always for list appearance
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetchAll();
            if (count($colCheck) > 0) {
                $stmt = $db->prepare("INSERT INTO users (name, email, phone) VALUES (:name, :email, :phone)");
                $stmt->execute([':name' => $name, ':email' => $email, ':phone' => $phone]);
            } else {
                $stmt = $db->prepare("INSERT INTO users (name, email) VALUES (:name, :email)");
                $stmt->execute([':name' => $name, ':email' => $email]);
            }
            $localId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception('Failed to save supplier locally: ' . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'action' => 'create_supplier',
            'message' => 'Supplier created',
            'id' => $localId,
            'name' => $name,
            'partner_id' => $partner_id
        ]);
        exit;
    }

    if ($action === 'delete_supplier') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $rawId = $data['id'] ?? $_REQUEST['id'] ?? null;
        if (!$rawId) throw new Exception('Supplier ID required');

        // Delete local supplier
        if (is_string($rawId) && preg_match('/^local-(\d+)$/', $rawId, $m)) {
            $localId = (int)$m[1];
            try {
                $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
                $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
                $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
                $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
                $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$localId]);

                echo json_encode(['success' => true, 'action' => 'delete_supplier', 'message' => 'Local supplier deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete local supplier: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid supplier id');
            try {
                $client->executePublic('res.partner', 'unlink', [[$partnerId]]);
                echo json_encode(['success' => true, 'action' => 'delete_supplier', 'message' => 'Odoo supplier deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete Odoo supplier: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'delete_partner') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $rawId = $data['id'] ?? $_REQUEST['id'] ?? null;
        if (!$rawId) throw new Exception('Partner ID required');

        // Delete local partner
        if (is_string($rawId) && preg_match('/^local-(\d+)$/', $rawId, $m)) {
            $localId = (int)$m[1];
            try {
                $dbHost = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
                $dbName = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'app');
                $dbUser = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
                $dbPass = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? '');
                $db = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$localId]);

                echo json_encode(['success' => true, 'action' => 'delete_partner', 'message' => 'Local partner deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete local partner: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid partner id');
            try {
                $client->executePublic('res.partner', 'unlink', [[$partnerId]]);
                echo json_encode(['success' => true, 'action' => 'delete_partner', 'message' => 'Odoo partner deleted']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to delete Odoo partner: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'cleanup_duplicate_partners') {
        // Fetch all partners (ID, Name) from Odoo
        $partners = $client->executePublic('res.partner', 'search_read', [[]], ['fields'=>['id','name']]);
        
        $groups = [];
        foreach ($partners as $p) {
            $nameNorm = trim(strtolower($p['name']));
            if (!$nameNorm) continue;
            if (!isset($groups[$nameNorm])) $groups[$nameNorm] = [];
            $groups[$nameNorm][] = $p;
        }
        
        $deletedCount = 0;
        $archivedCount = 0;
        $errors = [];
        
        foreach ($groups as $name => $list) {
            if (count($list) > 1) {
                // Determine which one to keep.
                // Strategy: Keep the one with the Lowest ID (assuming it's the original/oldest).
                // Sort by ID ascending.
                usort($list, function($a, $b) { return $a['id'] - $b['id']; });
                
                // Keep index 0, delete the rest
                $keep = $list[0];
                $toDelete = array_slice($list, 1);
                
                foreach ($toDelete as $dup) {
                    try {
                        // Attempt to delete
                        $client->executePublic('res.partner', 'unlink', [[$dup['id']]]);
                        $deletedCount++;
                    } catch (Exception $e) {
                         // Likely constraint error (linked records) -> Try archiving (active=false)
                         try {
                             $client->executePublic('res.partner', 'write', [[$dup['id']], ['active' => false]]);
                             $archivedCount++;
                         } catch (Exception $e2) {
                             $msg = $e->getMessage();
                             if (strpos($msg, 'xmlrpc') !== false) $msg = 'Odoo constrain error (cannot delete/archive)';
                             $errors[] = "Skip ID {$dup['id']} ('{$dup['name']}'): $msg";
                         }
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'action' => 'cleanup_duplicate_partners',
            'deleted_count' => $deletedCount,
            'archived_count' => $archivedCount,
            'total_analyzed' => count($partners),
            'message' => "Cleanup: $deletedCount deleted, $archivedCount archived. " . (count($errors) > 0 ? "Errors: " . implode("; ", array_slice($errors, 0, 3)) : "")
        ]);
        exit;
    }

    if ($action === 'get_product') {
        $product_id = (int)($_REQUEST['id'] ?? 0);
        if (!$product_id) throw new Exception('Product ID required');

        $product = $client->executePublic('product.product', 'read', [[$product_id]],
            ['fields' => ['id', 'name', 'default_code', 'list_price', 'standard_price', 'qty_available', 'virtual_available', 'incoming_qty', 'outgoing_qty', 'categ_id', 'barcode', 'type', 'description']]
        );

        if (!$product) throw new Exception('Product not found');

        echo json_encode([
            'success' => true,
            'action' => 'get_product',
            'data' => $product[0]
        ]);
        exit;
    }

    if ($action === 'update_product') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $product_id = (int)($data['id'] ?? 0);
        if (!$product_id) throw new Exception('Product ID required');

        $update_data = [];
        if (isset($data['name'])) $update_data['name'] = $data['name'];
        if (isset($data['list_price'])) $update_data['list_price'] = (float)$data['list_price'];
        if (isset($data['standard_price'])) $update_data['standard_price'] = (float)$data['standard_price'];
        if (isset($data['barcode'])) $update_data['barcode'] = $data['barcode'];
        if (isset($data['default_code'])) $update_data['default_code'] = $data['default_code'];

        if (empty($update_data)) throw new Exception('No data to update');

        $client->executePublic('product.product', 'write', [[$product_id], $update_data]);

        echo json_encode([
            'success' => true,
            'action' => 'update_product',
            'message' => 'Product updated',
            'product_id' => $product_id
        ]);
        exit;
    }

    if ($action === 'delete_product') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $product_id = (int)($data['id'] ?? $_REQUEST['id'] ?? 0);
        if (!$product_id) throw new Exception('Product ID required');

        $client->executePublic('product.product', 'unlink', [[$product_id]]);

        echo json_encode([
            'success' => true,
            'action' => 'delete_product',
            'message' => 'Product deleted',
            'product_id' => $product_id
        ]);
        exit;
    }

    if ($action === 'update_stock' || $action === 'adjust_stock') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $product_id = (int)($data['product_id'] ?? 0);
        $new_quantity = (float)($data['quantity'] ?? 0);
        $location_id = (int)($data['location_id'] ?? 8); // Default: Stock location

        if (!$product_id) throw new Exception('Product ID required');

        // Find stock quant record
        $quants = $client->executePublic('stock.quant', 'search_read',
            [[['product_id', '=', $product_id], ['location_id', '=', $location_id]]],
            ['fields' => ['id', 'quantity']]
        );

        if (!empty($quants)) {
            // Update existing
            $client->executePublic('stock.quant', 'write', 
                [[$quants[0]['id']], ['quantity' => $new_quantity]]
            );
        } else {
            // Create new quant
            $client->executePublic('stock.quant', 'create', [[
                'product_id' => $product_id,
                'location_id' => $location_id,
                'quantity' => $new_quantity,
                'reserved_quantity' => 0,
                'in_date' => date('Y-m-d H:i:s')
            ]]);
        }

        echo json_encode([
            'success' => true,
            'action' => 'update_stock',
            'message' => 'Stock updated',
            'product_id' => $product_id,
            'new_quantity' => $new_quantity
        ]);
        exit;
    }

    if ($action === 'get_stock_movements') {
        $limit = (int)($_REQUEST['limit'] ?? 50);
        
        $movements = $client->executePublic('stock.move', 'search_read',
            [[['state', '=', 'done']]],
            [
                'fields' => ['id', 'name', 'product_id', 'product_qty', 'location_id', 'location_dest_id', 'date', 'state'],
                'limit' => $limit,
                'order' => 'date desc'
            ]
        );

        echo json_encode([
            'success' => true,
            'action' => 'get_stock_movements',
            'count' => count($movements),
            'data' => $movements
        ]);
        exit;
    }

    if ($action === 'search_products') {
        $query = $_REQUEST['q'] ?? '';
        if (strlen($query) < 2) throw new Exception('Search query too short (minimum 2 characters)');

        $products = $client->executePublic('product.product', 'search_read',
            [[['name', 'ilike', $query]]],
            ['fields' => ['id', 'name', 'default_code', 'list_price', 'qty_available'], 'limit' => 20]
        );

        echo json_encode([
            'success' => true,
            'action' => 'search_products',
            'count' => count($products),
            'data' => $products
        ]);
        exit;
    }

    // ==================== LOCAL INVENTORY ====================

    if ($action === 'create_inventory') {
        $db = $getDb();
        $name = $_POST['name'] ?? null;
        if (!$name) throw new Exception('Product name required');

        $stmt = $db->prepare("INSERT INTO inventory (name, description, quantity, category, selling_price, cost_price, sku, product_type, status, synced_to_odoo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, NOW(), NOW())");
        $stmt->execute([
            $name,
            $_POST['description'] ?? null,
            (float)($_POST['quantity'] ?? 0),
            trim($_POST['category'] ?? '') ?: 'Uncategorized',
            (float)($_POST['selling_price'] ?? 0),
            (float)($_POST['cost_price'] ?? 0),
            $_POST['sku'] ?? null,
            $_POST['product_type'] ?? 'product'
        ]);

        echo json_encode(['success' => true, 'message' => 'Product created in local inventory', 'id' => $db->lastInsertId()]);
        exit;
    }

    if ($action === 'edit_inventory') {
        $db = $getDb();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');

        $fields = [];
        $params = [];
        $updatable = ['name', 'description', 'category', 'selling_price', 'cost_price', 'quantity', 'sku', 'product_type'];
        
        foreach ($updatable as $field) {
            if (isset($_POST[$field])) {
                $fields[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }

        if (empty($fields)) throw new Exception('No fields to update');

        $params[] = $id;
        $sql = "UPDATE inventory SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Local inventory updated']);
        exit;
    }

    // ==================== ERROR HANDLING ====================

    throw new Exception("Unknown action: {$action}");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $_REQUEST['action'] ?? 'unknown'
    ]);
}
?>
