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
// Start output buffering so any unexpected PHP notices or HTML don't break JSON responses
if (!ob_get_level()) ob_start();

// Capture any early output (before request processing) to help diagnose stray characters
$preOutput = '';
$early = ob_get_contents();
if (is_string($early) && trim($early) !== '') {
    $preOutput = $early;
    $tempDir = dirname(__DIR__, 1) . '/temp';
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
    $logFile = $tempDir . '/early_output_' . date('Ymd_His') . '.log';
    file_put_contents($logFile, $early);
    // clear buffer so normal handler can control output
    ob_clean();
}

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

    // Diagnostics container for Odoo read attempts (populated when helper runs)
    $odooReadDiagnostics = [];

    // Safe read helper to robustly read fields that may not exist on all Odoo versions.
    $safeRead = function($model, $ids, $fields) use ($client, &$odooReadDiagnostics) {
        // record initial attempt
        try {
            $res = $client->executePublic($model, 'read', [$ids, $fields]);
            $odooReadDiagnostics[] = ['attempt' => 'primary', 'fields' => $fields, 'success' => true];
            return $res;
        } catch (Exception $e) {
            $odooReadDiagnostics[] = ['attempt' => 'primary', 'fields' => $fields, 'success' => false, 'error' => $e->getMessage()];
            // Try pragmatic fallbacks: drop problematic fields (like 'residual') and try reduced sets.
            $fallbacks = [
                array_values(array_diff($fields, ['residual'])),
                ['id','name','partner_id','invoice_date','amount_total','state','move_type','invoice_line_ids','currency_id','journal_id'],
                ['id','name','invoice_line_ids'],
                ['id']
            ];
            foreach ($fallbacks as $idx => $fb) {
                try {
                    $res = $client->executePublic($model, 'read', [$ids, $fb]);
                    $odooReadDiagnostics[] = ['attempt' => 'fallback_' . ($idx + 1), 'fields' => $fb, 'success' => true];
                    return $res;
                } catch (Exception $e2) {
                    $odooReadDiagnostics[] = ['attempt' => 'fallback_' . ($idx + 1), 'fields' => $fb, 'success' => false, 'error' => $e2->getMessage()];
                    // continue to next fallback
                }
            }
            // if nothing works, rethrow original
            throw $e;
        }
    };

    // JSON send helper to ensure clean output (clears buffers and returns proper JSON)
    $sendJson = function($payload, $code = 200) use (&$preOutput) {
        // If debug requested, attach any early output we captured for diagnosis
        $debugMode = !empty($_REQUEST['debug']);
        if ($debugMode && !empty($preOutput)) {
            if (!isset($payload['debug'])) $payload['debug'] = [];
            $payload['debug']['early_output'] = $preOutput;
        }

        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($code);
        header('Content-Type: application/json');

        // Primary encode
        $out = json_encode($payload);
        if ($out === false) {
            $out = json_encode(['success' => false, 'error' => 'json_encode failed: ' . json_last_error_msg()]);
        }

        // Remove UTF-8 BOM if present
        if (strpos($out, "\xEF\xBB\xBF") === 0) $out = substr($out, 3);

        // Last-resort sanitization: strip anything before the first JSON char ({ or [)
        $firstBrace = strpos($out, '{');
        $firstBracket = strpos($out, '[');
        $firstPos = false;
        if ($firstBrace !== false && $firstBracket !== false) $firstPos = min($firstBrace, $firstBracket);
        elseif ($firstBrace !== false) $firstPos = $firstBrace;
        elseif ($firstBracket !== false) $firstPos = $firstBracket;

        $lastBrace = strrpos($out, '}');
        $lastBracket = strrpos($out, ']');
        $lastPos = false;
        if ($lastBrace !== false && $lastBracket !== false) $lastPos = max($lastBrace, $lastBracket);
        elseif ($lastBrace !== false) $lastPos = $lastBrace;
        elseif ($lastBracket !== false) $lastPos = $lastBracket;

        $sanitized = false;
        if ($firstPos !== false && $lastPos !== false && $lastPos >= $firstPos) {
            $candidate = substr($out, $firstPos, $lastPos - $firstPos + 1);
            // validate
            $decoded = json_decode($candidate);
            if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
                $out = $candidate;
                $sanitized = true;
            }
        }

        if ($sanitized && $debugMode) {
            // include note in payload's debug section if possible
            $dbg = ['sanitized' => true];
            if (!isset($payload['debug'])) $payload['debug'] = [];
            $payload['debug'] = array_merge($payload['debug'], $dbg);
            // ensure debug visible: re-encode and output sanitized JSON
            $out = json_encode($payload);
        }

        echo $out;
        exit;
    };

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
    
    // Purchase-specific logger for debugging problematic responses
    $logPurchase = function($tag, $data = []) {
        $tmp = dirname(__DIR__) . '/temp';
        if (!is_dir($tmp)) mkdir($tmp, 0777, true);
        $fn = $tmp . '/purchase_' . $tag . '_' . date('Ymd_His') . '.log';
        $payload = ['time' => date('c'), 'tag' => $tag, 'request' => $_REQUEST, 'data' => $data];
        file_put_contents($fn, json_encode($payload, JSON_PRETTY_PRINT));
    };

    if ($action === 'list_purchase_orders') {
        // Log incoming request
        if (isset($logPurchase)) $logPurchase('list_request', ['timestamp' => date('c')]);
        try {
            $orders = $client->executePublic('purchase.order', 'search_read',
                [[]],
                ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line', 'note', 'invoice_ids']]
            );

            // Format partner_id and ensure invoice_ids are present (fallback search by invoice_origin)
            foreach ($orders as &$order) {
                if (is_array($order['partner_id'])) {
                    $order['supplier_name'] = $order['partner_id'][1];
                    $order['supplier_id'] = $order['partner_id'][0];
                }

                // Normalize / ensure invoice_ids exists. Some Odoo setups may not return invoice_ids in search_read
                if (empty($order['invoice_ids'])) {
                    try {
                        // Try to find invoices referencing this PO by name (invoice_origin may contain the PO name)
                        $foundInvs = $client->executePublic('account.move', 'search_read', [[['invoice_origin', 'ilike', $order['name']]]], ['fields' => ['id']]);
                        if (is_array($foundInvs) && count($foundInvs) > 0) {
                            $order['invoice_ids'] = array_map(function($i){ return (int)($i['id'] ?? $i); }, $foundInvs);
                        } else {
                            $order['invoice_ids'] = [];
                        }
                    } catch (Exception $e) {
                        // If any error occurs, fall back to empty array
                        $order['invoice_ids'] = [];
                    }
                } else {
                    // Normalize invoice_ids to a simple array of ints
                    $order['invoice_ids'] = array_map(function($i){ return is_array($i) ? (int)$i[0] : (int)$i; }, (array)$order['invoice_ids']);
                }
            }

            // Log response for diagnostics
            if (isset($logPurchase)) $logPurchase('list_response', ['orders_count' => count($orders), 'sample' => array_slice($orders, 0, 5), 'odoo_diag' => $odooReadDiagnostics ?? null]);

            $sendJson([
                'success' => true,
                'action' => 'list_purchase_orders',
                'count' => count($orders),
                'data' => $orders
            ]);
        } catch (Exception $e) {
            // Log error details
            if (isset($logPurchase)) $logPurchase('list_error', ['error' => $e->getMessage()]);
            error_log('Error list_purchase_orders: ' . $e->getMessage());
            $sendJson([
                'success' => false,
                'action' => 'list_purchase_orders',
                'error' => 'Failed to fetch purchase orders: ' . $e->getMessage()
            ], 500);
        }

        exit;
    }

    if ($action === 'get_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (isset($logPurchase)) $logPurchase('get_request', ['id' => $po_id]);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        try {
            $order = $client->executePublic('purchase.order', 'read',
                [[$po_id]],
                ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line', 'note']]
            );
        } catch (Exception $e) {
            $sendJson(['success' => false, 'action' => 'get_purchase_order', 'error' => 'Odoo error: ' . $e->getMessage()], 500);
        }

        if (!$order) {
            $sendJson(['success' => false, 'action' => 'get_purchase_order', 'error' => 'Purchase Order not found'], 404);
        }

        $po = $order[0];
        
        // Get order lines
        if (!empty($po['order_line'])) {
            $lines = $client->executePublic('purchase.order.line', 'read',
                [$po['order_line']],
                ['fields' => ['id', 'product_id', 'name', 'product_qty', 'price_unit', 'price_subtotal','product_uom_qty','quantity']]
            );

            // Normalize quantities and subtotals so frontend JS can rely on `product_qty`
            foreach ($lines as &$ln) {
                $qty = 0.0;
                if (isset($ln['product_qty'])) {
                    $qty = (float)$ln['product_qty'];
                } elseif (isset($ln['product_uom_qty'])) {
                    $qty = (float)$ln['product_uom_qty'];
                } elseif (isset($ln['quantity'])) {
                    $qty = (float)$ln['quantity'];
                }
                $ln['product_qty'] = $qty;

                if (!isset($ln['price_subtotal']) || $ln['price_subtotal'] === null) {
                    $unit = isset($ln['price_unit']) ? (float)$ln['price_unit'] : 0.0;
                    $ln['price_subtotal'] = $unit * $qty;
                }
            }
            unset($ln);

            $po['lines'] = $lines;
        }

        if (isset($logPurchase)) $logPurchase('get_response', ['po' => $po, 'lines_sample' => array_slice($po['lines'] ?? [], 0, 5), 'odoo_diag' => $odooReadDiagnostics ?? null]);

        $sendJson([
            'success' => true,
            'action' => 'get_purchase_order',
            'data' => $po
        ]);
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

                $partner_data = ['name' => $user['name'], 'email' => $user['email']];
                // Odoo 17+ compatibility: supplier_rank removed
                $newPartnerId = $client->executePublic('res.partner', 'create', [$partner_data]);
                $partnerIdToUse = is_array($newPartnerId) ? $newPartnerId[0] : $newPartnerId;
            } catch (Exception $e) {
                $sendJson(['success' => false, 'error' => 'Failed to create partner in Odoo: ' . $e->getMessage()], 500);
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
                // Safeguard against invalid/placeholder product IDs (like 1 or 0)
                $pid = (isset($line['product_id'])) ? (int)$line['product_id'] : 0;
                $originalPid = $pid;
                $odooId = null;

                // Map local inventory ID to Odoo product ID
                try {
                    $db = $getDb();
                    $stmt = $db->prepare("SELECT name, odoo_id FROM inventory WHERE id = ?");
                    $stmt->execute([$pid]);
                    $inv = $stmt->fetch();
                    if ($inv && $inv['odoo_id']) {
                        $odooId = (int)$inv['odoo_id'];
                    }
                } catch (Exception $e) {}

                if (!$odooId) {
                    $prodName = $inv['name'] ?? "ID $originalPid";
                    throw new Exception("Product '$prodName' tidak terhubung ke Odoo. Silakan tekan tombol 'Sync' di halaman List Barang Odoo.");
                }

                $po_data['order_line'][] = [0, 0, [
                    'product_id' => $odooId,
                    'product_qty' => (float)($line['quantity'] ?? $line['product_qty'] ?? 0),
                    'price_unit' => (float)($line['price'] ?? $line['price_unit'] ?? 0)
                ]];
            }
        }

        try {
            $po_id = $client->executePublic('purchase.order', 'create', [$po_data]);
        } catch (Exception $e) {
            $sendJson([
                'success' => false,
                'action' => 'create_purchase_order',
                'error' => 'Odoo error: ' . $e->getMessage()
            ], 500);
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

        $sendJson([
            'success' => true,
            'action' => 'create_purchase_order',
            'message' => 'Purchase Order created',
            'po_id' => $po_id,
            'order' => $createdOrder
        ]);
    }

    if ($action === 'update_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $update_data = [];
        if (isset($data['notes'])) $update_data['note'] = $data['notes'];
        if (isset($data['date_order'])) $update_data['date_order'] = $data['date_order'];

        $client->executePublic('purchase.order', 'write', [[$po_id], $update_data]);

        $sendJson([
            'success' => true,
            'action' => 'update_purchase_order',
            'message' => 'Purchase Order updated'
        ]);
    }

    if ($action === 'approve_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $client->executePublic('purchase.order', 'button_confirm', [[$po_id]]);

        $poName = null; // filled later when searching invoices

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

        // Auto-create Bill (Invoice)
        $createdInvoiceIds = [];
        try {
            $client->executePublic('purchase.order', 'action_create_invoice', [[$po_id]]);

            // Try to find invoices created from this PO by matching invoice_origin
            try {
                $poRead = $client->executePublic('purchase.order', 'read', [[$po_id], ['name']]);
                $poName = $poRead && !empty($poRead[0]['name']) ? $poRead[0]['name'] : null;
                if ($poName) {
                    $foundInvs = $client->executePublic('account.move', 'search_read', [[['invoice_origin', 'ilike', $poName]]], ['fields' => ['id']]);
                    if (is_array($foundInvs) && count($foundInvs) > 0) {
                        $createdInvoiceIds = array_map(function($i){ return (int)($i['id'] ?? $i); }, $foundInvs);
                    }
                }
            } catch (Exception $e) {
                error_log('Warning: unable to read invoices after auto-create for PO '.$po_id.': '.$e->getMessage());
            }
        } catch (Exception $e) {
            error_log("Auto-create-bill failed for PO $po_id: " . $e->getMessage());

            // Fallback: try to build and create an invoice from PO lines ourselves
            try {
                $poR = $client->executePublic('purchase.order', 'read', [[$po_id], ['id','name','partner_id','order_line']]);
                if (!empty($poR) && is_array($poR)) {
                    $poObj = $poR[0];
                    $lineItems = [];
                    if (!empty($poObj['order_line'])) {
                        $lineItems = $client->executePublic('purchase.order.line', 'read', [$poObj['order_line'], ['product_id','name','product_qty','price_unit','product_uom_qty']]);
                    }

                    $linesForInvoice = [];
                    foreach ($lineItems as $li) {
                        $prod = $li['product_id'] ?? null;
                        $product_id = is_array($prod) ? (int)$prod[0] : (int)$prod;
                        $qty = (float)($li['product_qty'] ?? $li['product_uom_qty'] ?? 0);
                        $price = (float)($li['price_unit'] ?? 0);

                        $linePayload = ['product_id' => $product_id > 0 ? $product_id : null, 'name' => $li['name'] ?? '', 'quantity' => $qty, 'price_unit' => $price];
                        $linesForInvoice[] = [0, 0, $linePayload];
                    }

                    if (!empty($linesForInvoice)) {
                        // Try to pick a suitable purchase journal for vendor bills
                        $journalId = null;
                        try {
                            $journals = $client->executePublic('account.journal', 'search_read', [[['type', '=', 'purchase']]], ['fields' => ['id']]);
                            if (!empty($journals) && is_array($journals)) {
                                $journalId = (int)($journals[0]['id'] ?? $journals[0]);
                            }
                        } catch (Exception $e) { /* ignore */ }

                        $invoicePayload = [
                            'partner_id' => is_array($poObj['partner_id']) ? (int)$poObj['partner_id'][0] : (int)$poObj['partner_id'],
                            'move_type' => 'in_invoice',
                            'invoice_date' => date('Y-m-d'),
                            'invoice_date_due' => date('Y-m-d'),
                            'invoice_line_ids' => $linesForInvoice,
                            'invoice_origin' => $poObj['name'] ?? null
                        ];

                        if ($journalId) $invoicePayload['journal_id'] = $journalId;

                        $invCreate = $client->executePublic('account.move', 'create', [$invoicePayload]);
                        if (is_array($invCreate)) $invCreate = $invCreate[0];
                        if ($invCreate) {
                            $createdInvoiceIds[] = (int)$invCreate;
                            // Attempt to post immediately (will be retried below too)
                            try { $client->executePublic('account.move', 'action_post', [[$invCreate]]); } catch (Exception $e2) { error_log('Warning posting fallback invoice '.$invCreate.': '.$e2->getMessage()); }
                        }
                    }
                }
            } catch (Exception $e2) {
                error_log('Fallback invoice-creation also failed for PO '.$po_id.': '.$e2->getMessage());
            }
        }

        // Attempt to post any created invoices so they become visible as 'posted'
        $postedInvoiceIds = [];
        $posting_errors = [];
        foreach ($createdInvoiceIds as $ci) {
            $posted = false;
            $lastErr = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $client->executePublic('account.move', 'action_post', [[(int)$ci]]);
                    $postedInvoiceIds[] = (int)$ci;
                    $posted = true;
                    break;
                } catch (Exception $e) {
                    $lastErr = $e;
                    // On first failure, try to set invoice_date if missing (common cause)
                    if ($attempt === 1) {
                        try {
                            $invRead = $client->executePublic('account.move', 'read', [[(int)$ci], ['invoice_date']]);
                            if (!empty($invRead) && empty($invRead[0]['invoice_date'])) {
                                $client->executePublic('account.move', 'write', [[(int)$ci], ['invoice_date' => date('Y-m-d')]]);
                            }
                        } catch (Exception $e2) {
                            // ignore write failures
                        }
                    }

                    // If this was the last attempt, try alternative posting methods used by different Odoo versions
                    if ($attempt === 3) {
                        try {
                            // try button_validate (some versions/flows require it)
                            $client->executePublic('account.move', 'button_validate', [[(int)$ci]]);
                            // attempt post again
                            $client->executePublic('account.move', 'action_post', [[(int)$ci]]);
                            $postedInvoiceIds[] = (int)$ci;
                            $posted = true;
                            break;
                        } catch (Exception $e3) {
                            // try older method names
                            try {
                                $client->executePublic('account.move', 'action_invoice_open', [[(int)$ci]]);
                                $postedInvoiceIds[] = (int)$ci;
                                $posted = true;
                                break;
                            } catch (Exception $e4) {
                                // give up for this invoice
                                $lastErr = $e4;
                            }
                        }
                    }

                    // short backoff
                    usleep(200000);
                }
            }

            if (!$posted) {
                $msg = $lastErr ? $lastErr->getMessage() : 'Unknown post error';
                $posting_errors[(int)$ci] = $msg;
                error_log('Warning: failed to post invoice '.$ci.' for PO '.$po_id.': '.$msg);
            }
        }

        $out = [
            'success' => true,
            'action' => 'approve_purchase_order',
            'message' => 'Purchase Order approved',
            'new_state' => 'purchase',
            'po_name' => $poName ?? null,
            'invoice_ids' => $createdInvoiceIds,
            'posted_invoice_ids' => $postedInvoiceIds
        ];
        if (!empty($posting_errors)) $out['posting_errors'] = $posting_errors;
        $sendJson($out);
    }

    if ($action === 'reject_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        $client->executePublic('purchase.order', 'button_cancel', [[$po_id]]);

        $sendJson([
            'success' => true,
            'action' => 'reject_purchase_order',
            'message' => 'Purchase Order cancelled',
            'new_state' => 'cancel'
        ]);
    }

    if ($action === 'delete_purchase_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        if (!$po_id) throw new Exception('Purchase Order ID required');

        try {
            // Read current state so we can cancel first if necessary
            $poRead = $client->executePublic('purchase.order', 'read', [[$po_id]], ['fields' => ['id','state','order_line']]);
            if (empty($poRead)) throw new Exception('Purchase Order not found');
            $po = $poRead[0];
            $state = $po['state'] ?? 'draft';

            // If not already cancelled/draft, attempt to cancel first
            if (!in_array($state, ['cancel', 'draft'], true)) {
                try {
                    $client->executePublic('purchase.order', 'button_cancel', [[$po_id]]);
                } catch (Exception $e) {
                    $msg = $e->getMessage();
                    // Handle XML-RPC marshalling issue where server action looks like it executed but returned None
                    if (stripos($msg, 'cannot marshal None') !== false || stripos($msg, 'cannot marshal None unless allow_none') !== false || (stripos($msg, 'TypeError') !== false && stripos($msg, 'marshal') !== false)) {
                        // Verify the state; if cancelled, continue, otherwise surface error
                        try {
                            $verify = $client->executePublic('purchase.order', 'read', [[$po_id]], ['fields' => ['state']]);
                            if (empty($verify) || ($verify[0]['state'] ?? '') !== 'cancel') {
                                error_log('Cancel returned marshal error but PO state not cancel for '.$po_id);
                                throw new Exception('Failed to cancel purchase order before delete: ' . $msg);
                            }
                            // else: state is cancel, proceed to unlink
                        } catch (Exception $re) {
                            // If verification fails, log and continue to attempt unlink as a best-effort
                            error_log('Verify after marshal error failed for PO '.$po_id.': '.$re->getMessage());
                        }
                    } else {
                        throw new Exception('Failed to cancel purchase order before delete: ' . $msg);
                    }
                }
            }

            // Attempt to unlink after cancel/draft
            $client->executePublic('purchase.order', 'unlink', [[$po_id]]);

            $sendJson([
                'success' => true,
                'action' => 'delete_purchase_order',
                'message' => 'Purchase Order deleted'
            ]);
            return;

        } catch (Exception $e) {
            $msg = $e->getMessage();
            // If unlink failed due to "must cancel first", or a marshalling error occurred, try a robust flow
            if (stripos($msg, 'must cancel') !== false || stripos($msg, 'cancel') !== false || stripos($msg, 'cannot marshal None') !== false || stripos($msg, 'cannot marshal None unless allow_none') !== false) {
                try {
                    // Attempt cancel again (best-effort, may raise marshal error which we handle below)
                    try {
                        $client->executePublic('purchase.order', 'button_cancel', [[$po_id]]);
                    } catch (Exception $e3) {
                        // If marshal error, try to verify cancellation, otherwise continue
                        $m3 = $e3->getMessage();
                        if (stripos($m3, 'cannot marshal None') !== false || stripos($m3, 'cannot marshal None unless allow_none') !== false) {
                            error_log('button_cancel returned marshal error on retry for PO '.$po_id.': '.$m3);
                        } else {
                            throw $e3;
                        }
                    }

                    // Attempt unlink
                    $client->executePublic('purchase.order', 'unlink', [[$po_id]]);

                    $sendJson([
                        'success' => true,
                        'action' => 'delete_purchase_order',
                        'message' => 'Purchase Order cancelled then deleted'
                    ]);
                    return;

                } catch (Exception $e2) {
                    $m2 = $e2->getMessage();
                    error_log('Delete purchase_order retry after cancel failed for '.$po_id.': '.$m2);

                    // If the retry failed due to marshal error, try to verify whether the record was actually deleted
                    if (stripos($m2, 'cannot marshal None') !== false || stripos($m2, 'cannot marshal None unless allow_none') !== false) {
                        try {
                            $verifyDel = $client->executePublic('purchase.order', 'read', [[$po_id]], ['fields' => ['id','state']]);
                            if (empty($verifyDel)) {
                                $sendJson(['success' => true, 'action' => 'delete_purchase_order', 'message' => 'Purchase Order deleted (verification: not found, xmlrpc marshal error occurred)']);
                                return;
                            } else {
                                error_log('Delete retry returned marshal error but PO still exists: '.$po_id);
                                http_response_code(400);
                                $sendJson(['success' => false, 'action' => 'delete_purchase_order', 'error' => 'Delete failed after cancel attempt (verified still exists): ' . $m2]);
                                return;
                            }
                        } catch (Exception $verifyExc) {
                            error_log('After marshal error we could not verify deletion for '.$po_id.': '.$verifyExc->getMessage());
                            http_response_code(400);
                            $sendJson(['success' => false, 'action' => 'delete_purchase_order', 'error' => 'Delete failed and verification unavailable: ' . $m2 . ' / verify: ' . $verifyExc->getMessage()]);
                            return;
                        }
                    }

                    http_response_code(400);
                    $sendJson(['success' => false, 'action' => 'delete_purchase_order', 'error' => 'Delete failed after cancel attempt: ' . $m2]);
                    return;
                }
            }

            http_response_code(400);
            $sendJson(['success' => false, 'action' => 'delete_purchase_order', 'error' => $msg]);
            return;
        }
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

            $sendJson([
                'success' => true,
                'action' => 'delete_sales_order',
                'message' => 'Sales Order deleted'
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            $sendJson([
                'success' => false,
                'action' => 'delete_sales_order',
                'error' => 'Failed to delete sales order: ' . $e->getMessage()
            ], 400);
        }
    }

    // ==================== SALES ORDERS ====================

    if ($action === 'list_sales_orders') {
        $orders = $client->executePublic('sale.order', 'search_read',
            [[]],
            ['fields' => ['id', 'name', 'partner_id', 'date_order', 'amount_total', 'state', 'order_line', 'invoice_ids']]
        );

        foreach ($orders as &$order) {
            if (is_array($order['partner_id'])) {
                $order['customer_name'] = $order['partner_id'][1];
                $order['customer_id'] = $order['partner_id'][0];
            }

            // Normalize / ensure invoice_ids exists similar to purchase orders
            if (empty($order['invoice_ids'])) {
                try {
                    $foundInvs = $client->executePublic('account.move', 'search_read', [[['invoice_origin', 'ilike', $order['name']]]], ['fields' => ['id']]);
                    if (is_array($foundInvs) && count($foundInvs) > 0) {
                        $order['invoice_ids'] = array_map(function($i){ return (int)($i['id'] ?? $i); }, $foundInvs);
                    } else {
                        $order['invoice_ids'] = [];
                    }
                } catch (Exception $e) {
                    $order['invoice_ids'] = [];
                }
            } else {
                $order['invoice_ids'] = array_map(function($i){ return is_array($i) ? (int)$i[0] : (int)$i; }, (array)$order['invoice_ids']);
            }
        }

        $sendJson([
            'success' => true,
            'action' => 'list_sales_orders',
            'count' => count($orders),
            'data' => $orders
        ]);
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
                'name' => $user['name']
            ];
            // Odoo 17+ compatibility: customer_rank removed
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

        $sendJson([
            'success' => true,
            'action' => 'create_sales_order',
            'message' => 'Sales Order created',
            'so_id' => $so_id
        ]);
    }

    if ($action === 'confirm_sales_order') {
        $so_id = (int)($_REQUEST['id'] ?? 0);
        if (!$so_id) throw new Exception('Sales Order ID required');

        $client->executePublic('sale.order', 'action_confirm', [[$so_id]]);

        $soName = null; // filled later when searching invoices

        // Auto-create Invoice so it appears in Odoo Invoicing and can be viewed
        $createdInvoiceIds = [];
        try {
            $client->executePublic('sale.order', '_create_invoices', [[$so_id]]);

            // Find created invoices by invoice_origin referencing the SO name
            try {
                $soRead = $client->executePublic('sale.order', 'read', [[$so_id], ['name']]);
                $soName = $soRead && !empty($soRead[0]['name']) ? $soRead[0]['name'] : null;
                if ($soName) {
                    $foundInvs = $client->executePublic('account.move', 'search_read', [[['invoice_origin', 'ilike', $soName]]], ['fields' => ['id']]);
                    if (is_array($foundInvs) && count($foundInvs) > 0) {
                        $createdInvoiceIds = array_map(function($i){ return (int)($i['id'] ?? $i); }, $foundInvs);
                    }
                }
            } catch (Exception $e) {
                error_log('Warning: unable to read invoices after auto-create for SO '.$so_id.': '.$e->getMessage());
            }
        } catch (Exception $e) {
            error_log("Auto-create-invoice failed for SO $so_id: " . $e->getMessage());

            // Fallback: try to build and create invoice from SO lines ourselves
            try {
                $soR = $client->executePublic('sale.order', 'read', [[$so_id], ['id','name','partner_id','order_line']]);
                if (!empty($soR) && is_array($soR)) {
                    $soObj = $soR[0];
                    $lineItems = [];
                    if (!empty($soObj['order_line'])) {
                        $lineItems = $client->executePublic('sale.order.line', 'read', [$soObj['order_line'], ['product_id','name','product_uom_qty','price_unit']]);
                    }

                    $linesForInvoice = [];
                    foreach ($lineItems as $li) {
                        $prod = $li['product_id'] ?? null;
                        $product_id = is_array($prod) ? (int)$prod[0] : (int)$prod;
                        $qty = (float)($li['product_uom_qty'] ?? $li['product_qty'] ?? 0);
                        $price = (float)($li['price_unit'] ?? 0);

                        $linePayload = ['product_id' => $product_id > 0 ? $product_id : null, 'name' => $li['name'] ?? '', 'quantity' => $qty, 'price_unit' => $price];
                        $linesForInvoice[] = [0, 0, $linePayload];
                    }

                    if (!empty($linesForInvoice)) {
                        $invoicePayload = [
                            'partner_id' => is_array($soObj['partner_id']) ? (int)$soObj['partner_id'][0] : (int)$soObj['partner_id'],
                            'move_type' => 'out_invoice',
                            'invoice_date' => date('Y-m-d'),
                            'invoice_line_ids' => $linesForInvoice,
                            'invoice_origin' => $soObj['name'] ?? null
                        ];

                        $invCreate = $client->executePublic('account.move', 'create', [$invoicePayload]);
                        if (is_array($invCreate)) $invCreate = $invCreate[0];
                        if ($invCreate) {
                            $createdInvoiceIds[] = (int)$invCreate;
                            try { $client->executePublic('account.move', 'action_post', [[$invCreate]]); } catch (Exception $e2) { error_log('Warning posting fallback invoice '.$invCreate.': '.$e2->getMessage()); }
                        }
                    }
                }
            } catch (Exception $e2) {
                error_log('Fallback invoice-creation also failed for SO '.$so_id.': '.$e2->getMessage());
            }
        }

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

        // Attempt to post any created invoices so they become visible as 'posted'
        $postedInvoiceIds = [];
        $posting_errors = [];
        foreach ($createdInvoiceIds as $ci) {
            $posted = false;
            $lastErr = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $client->executePublic('account.move', 'action_post', [[(int)$ci]]);
                    $postedInvoiceIds[] = (int)$ci;
                    $posted = true;
                    break;
                } catch (Exception $e) {
                    $lastErr = $e;
                    if ($attempt === 1) {
                        try {
                            $invRead = $client->executePublic('account.move', 'read', [[(int)$ci], ['invoice_date']]);
                            if (!empty($invRead) && empty($invRead[0]['invoice_date'])) {
                                $client->executePublic('account.move', 'write', [[(int)$ci], ['invoice_date' => date('Y-m-d')]]);
                            }
                        } catch (Exception $e2) {}
                    }
                    usleep(200000);
                }
            }

            if (!$posted) {
                $msg = $lastErr ? $lastErr->getMessage() : 'Unknown post error';
                $posting_errors[(int)$ci] = $msg;
                error_log('Warning: failed to post invoice '.$ci.' for SO '.$so_id.': '.$msg);
            }
        }

        $out = [
            'success' => true,
            'action' => 'confirm_sales_order',
            'message' => 'Sales Order confirmed',
            'new_state' => 'sale',
            'so_name' => $soName ?? null,
            'invoice_ids' => $createdInvoiceIds,
            'posted_invoice_ids' => $postedInvoiceIds
        ];
        if (!empty($posting_errors)) $out['posting_errors'] = $posting_errors;
        $sendJson($out);
    }

    if ($action === 'cancel_sales_order') {
        $so_id = (int)($_REQUEST['id'] ?? 0);
        if (!$so_id) throw new Exception('Sales Order ID required');

        $client->executePublic('sale.order', 'action_cancel', [[$so_id]]);

        $sendJson([
            'success' => true,
            'action' => 'cancel_sales_order',
            'message' => 'Sales Order cancelled',
            'new_state' => 'cancel'
        ]);
    }

    // ==================== INVOICES ====================

    if ($action === 'list_invoices') {
        $invoices = $client->executePublic('account.move', 'search_read',
            [[['move_type', 'in', ['in_invoice', 'out_invoice']]]],
            ['fields' => ['id', 'name', 'partner_id', 'invoice_date', 'invoice_origin', 'amount_untaxed', 'amount_total', 'state', 'move_type', 'currency_id', 'invoice_line_ids']]
        );

        $sendJson([
            'success' => true,
            'action' => 'list_invoices',
            'count' => count($invoices),
            'data' => $invoices
        ]);
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
            $sendJson([
                'success' => true, 
                'action' => 'create_invoice', 
                'message' => 'Invoice created successfully', 
                'invoice_id' => $invoiceId,
                'posted' => $posted
            ]);
        } catch (Exception $e) {
            error_log("Odoo Create Invoice Error: " . $e->getMessage());
            $sendJson([
                'success' => false, 
                'action' => 'create_invoice', 
                'error' => $e->getMessage()
            ], 500);
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

        $sendJson([
            'success' => true,
            'action' => 'get_partner_orders',
            'data' => [
                'sales' => $sales,
                'purchases' => $purchases
            ]
        ]);
    }

    if ($action === 'post_invoice') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $inv_id = (int)($data['id'] ?? 0);
        if (!$inv_id) throw new Exception('Invoice ID required');

        try {
            $client->executePublic('account.move', 'action_post', [[$inv_id]]);
            $sendJson(['success' => true, 'action' => 'post_invoice', 'message' => 'Invoice posted']);
            exit;
        } catch (Exception $e) {
            $sendJson(['success' => false, 'error' => $e->getMessage()], 500);
            exit;
        }
    }

    if ($action === 'register_payment') {
        // This endpoint has been removed. Use Odoo UI or a dedicated accounting workflow to register payments.
        $sendJson(['success' => false, 'action' => 'register_payment', 'error' => 'register_payment action removed from public API'], 400);
        exit;
    }

    // Get a single invoice with lines (AJAX)
    if ($action === 'get_invoice') {
        $inv_id = (int)($_REQUEST['id'] ?? 0);
        $order_id = (int)($_REQUEST['order_id'] ?? 0);
        if (!$inv_id && !$order_id) throw new Exception('Invoice ID required');

        // Attempt to read by invoice id if provided
        $invoice = null;
        if ($inv_id) {
            $fields = ['id','name','partner_id','invoice_date','invoice_date_due','amount_untaxed','amount_tax','amount_total','residual','state','move_type','invoice_line_ids','narration','invoice_origin','currency_id','journal_id','invoice_payment_term_id','fiscal_position_id','tax_line_ids'];
            try {
                $invRead = $safeRead('account.move', [$inv_id], $fields);
                if (!empty($invRead)) $invoice = $invRead[0];
            } catch (Exception $e) {
                error_log('Warning: account.move read failed for inv_id ' . $inv_id . ': ' . $e->getMessage());
                $invoice = null;
            }
        }

        // Fallback: if not found and order_id provided, try to locate invoice by order name (invoice_origin)
        if (empty($invoice) && $order_id) {
            try {
                $ord = $client->executePublic('purchase.order', 'read', [[$order_id], ['id','name']]);
                if (empty($ord)) {
                    $ord = $client->executePublic('sale.order', 'read', [[$order_id], ['id','name']]);
                }
                $ord = !empty($ord) ? $ord[0] : null;
                $orderName = $ord['name'] ?? null;
                if ($orderName) {
                    $found = $client->executePublic('account.move', 'search_read', [[['invoice_origin', 'ilike', $orderName]]], ['fields' => ['id']]);
                    if (!empty($found) && is_array($found)) {
                        $foundId = (int)$found[0]['id'];
                        $fields = ['id','name','partner_id','invoice_date','invoice_date_due','amount_untaxed','amount_tax','amount_total','residual','state','move_type','invoice_line_ids','narration','invoice_origin','currency_id','journal_id','invoice_payment_term_id','fiscal_position_id','tax_line_ids'];
                        try {
                            $invRead = $safeRead('account.move', [$foundId], $fields);
                            if (!empty($invRead)) $invoice = $invRead[0];
                        } catch (Exception $e) {
                            error_log('Warning: account.move read failed for foundId ' . $foundId . ': ' . $e->getMessage());
                            $invoice = null;
                        }
                    }
                }
            } catch (Exception $e) {
                // ignore fallback failures
            }
        }

        if (empty($invoice)) throw new Exception('Invoice not found');

        // Resolve partner details
        $partnerObj = null;
        if (!empty($invoice['partner_id']) && is_array($invoice['partner_id'])) {
            $pid = (int)$invoice['partner_id'][0];
            try {
                $p = $client->executePublic('res.partner', 'read', [[$pid], ['id','name','street','city','vat','email','phone']]);
                if (!empty($p)) $partnerObj = $p[0];
            } catch (Exception $e) {}
            $invoice['partner'] = $partnerObj ?? ['id' => $pid, 'name' => $invoice['partner_id'][1]];
        }

        // Party role: supplier for in_invoice, customer for out_invoice
        $invoice['party_role'] = (isset($invoice['move_type']) && $invoice['move_type'] === 'in_invoice') ? 'supplier' : 'customer';

        // If invoice has an origin (like PO or SO), try to fetch the originating order details (date, state, partner)
        $invoice['origin_order'] = null;
        if (!empty($invoice['invoice_origin'])) {
            try {
                // Prefer purchase.order, then sale.order
                $found = $client->executePublic('purchase.order', 'search_read', [[['name', '=', $invoice['invoice_origin']]]], ['fields' => ['id','name','date_order','state','partner_id']]);
                if (empty($found)) {
                    $found = $client->executePublic('sale.order', 'search_read', [[['name', '=', $invoice['invoice_origin']]]], ['fields' => ['id','name','date_order','state','partner_id']]);
                }
                if (!empty($found) && is_array($found)) {
                    $ord = $found[0];
                    if (!empty($ord['partner_id']) && is_array($ord['partner_id'])) {
                        $ord['partner_name'] = $ord['partner_id'][1];
                        $ord['partner_id'] = (int)$ord['partner_id'][0];
                    }
                    $invoice['origin_order'] = $ord;
                }
            } catch (Exception $e) {
                // ignore origin lookup failures
            }
        }

        // Display number and posted flag
        $displayNumber = (isset($invoice['name']) && $invoice['name'] !== false && $invoice['name'] !== '') ? $invoice['name'] : ('Draft #' . $inv_id);
        $is_posted = isset($invoice['state']) && in_array($invoice['state'], ['posted','posted']) ? true : false;

        // Tax summary (best-effort)
        $taxSummary = [];
        if (!empty($invoice['tax_line_ids']) && is_array($invoice['tax_line_ids'])) {
            try {
                $taxLines = $client->executePublic('account.move.tax', 'read', [$invoice['tax_line_ids'], ['id','name','amount']]);
                foreach ($taxLines as $t) {
                    $taxSummary[] = ['id' => (int)$t['id'], 'name' => $t['name'] ?? '', 'amount' => (float)($t['amount'] ?? 0)];
                }
            } catch (Exception $e) {
                // ignore if model not available
            }
        }

        // Enrich lines with product details (with robust fallbacks)
        $lines = [];
        $calculatedSubtotal = 0.0;
        $raw = [];
        $fallback_used = false;

        // Primary: invoice_line_ids
        if (!empty($invoice['invoice_line_ids'])) {
            try {
                $raw = $client->executePublic('account.move.line', 'read', [$invoice['invoice_line_ids'], ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
            } catch (Exception $e) { $raw = []; }
        }

        // Fallback 1: line_ids
        if (empty($raw) && !empty($invoice['line_ids'])) {
            try {
                $raw = $client->executePublic('account.move.line', 'read', [$invoice['line_ids'], ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
                if (!empty($raw)) $fallback_used = 'line_ids';
            } catch (Exception $e) { $raw = []; }
        }

        // Fallback 2: search by move_id
        if (empty($raw) && !empty($invoice['id'])) {
            try {
                $raw = $client->executePublic('account.move.line', 'search_read', [[['move_id','=', (int)$invoice['id']]]], ['fields' => ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
                if (!empty($raw)) $fallback_used = 'search_by_move_id';
            } catch (Exception $e) { $raw = []; }
        }

        // Enrich product map and build lines
        $productIds = [];
        foreach ($raw as $rl) {
            $p = $rl['product_id'] ?? null;
            $pid = is_array($p) ? (int)$p[0] : (int)$p;
            if ($pid) $productIds[$pid] = $pid;
        }

        $productsMap = [];
        if (!empty($productIds)) {
            try {
                $pd = $client->executePublic('product.product', 'read', [array_values($productIds), ['id','name','default_code']]);
                foreach ($pd as $pp) $productsMap[(int)$pp['id']] = $pp;
            } catch (Exception $e) {}
        }

        $formatAmount = function($a) { return 'Rp ' . number_format((float)$a, 0, ',', '.'); };

        foreach ($raw as $r) {
            $prod = $r['product_id'] ?? null;
            $prodId = is_array($prod) ? (int)$prod[0] : ((int)$prod ?: null);
            $prodName = is_array($prod) ? ($prod[1] ?? null) : null;
            $defaultCode = $prodId && isset($productsMap[$prodId]) ? ($productsMap[$prodId]['default_code'] ?? null) : null;

            $priceUnit = (float)($r['price_unit'] ?? 0);
            $sub = (float)($r['price_subtotal'] ?? ($priceUnit * ($r['quantity'] ?? 0)));
            $calculatedSubtotal += $sub;

            $lines[] = [
                'id' => (int)$r['id'],
                'product_id' => $prodId,
                'product_name' => $prodName ?? $r['name'],
                'product_sku' => $defaultCode,
                'name' => $r['name'],
                'quantity' => (float)($r['quantity'] ?? 0),
                'price_unit' => $priceUnit,
                'price_unit_formatted' => $formatAmount($priceUnit),
                'price_subtotal' => $sub,
                'price_subtotal_formatted' => $formatAmount($sub),
                'account_id' => !empty($r['account_id']) && is_array($r['account_id']) ? (int)$r['account_id'][0] : ($r['account_id'] ?? null)
            ];
        }

        if ($fallback_used) {
            $invoice['_fallback_used'] = $fallback_used;
        }

        // Resolve journal, payment term, fiscal position (best-effort)
        $journal = null; $paymentTerm = null; $fiscal = null;
        try {
            if (!empty($invoice['journal_id']) && is_array($invoice['journal_id'])) {
                $j = $client->executePublic('account.journal', 'read', [[(int)$invoice['journal_id'][0]], ['id','name']]);
                if (!empty($j)) $journal = $j[0];
            }
        } catch (Exception $e) {}
        try {
            if (!empty($invoice['invoice_payment_term_id']) && is_array($invoice['invoice_payment_term_id'])) {
                $pt = $client->executePublic('account.payment.term', 'read', [[(int)$invoice['invoice_payment_term_id'][0]], ['id','name']]);
                if (!empty($pt)) $paymentTerm = $pt[0];
            }
        } catch (Exception $e) {}
        try {
            if (!empty($invoice['fiscal_position_id']) && is_array($invoice['fiscal_position_id'])) {
                $fp = $client->executePublic('account.fiscal.position', 'read', [[(int)$invoice['fiscal_position_id'][0]], ['id','name']]);
                if (!empty($fp)) $fiscal = $fp[0];
            }
        } catch (Exception $e) {}

        // Currency handling
        $currencyCode = null;
        if (!empty($invoice['currency_id']) && is_array($invoice['currency_id'])) $currencyCode = $invoice['currency_id'][1] ?? null;
        $currencySymbol = 'Rp'; if ($currencyCode && strtoupper($currencyCode) !== 'IDR') $currencySymbol = $currencyCode;

        $amountTotal = isset($invoice['amount_total']) ? (float)$invoice['amount_total'] : $calculatedSubtotal;
        $amountUntaxed = isset($invoice['amount_untaxed']) ? (float)$invoice['amount_untaxed'] : $calculatedSubtotal;
        $amountTax = isset($invoice['amount_tax']) ? (float)$invoice['amount_tax'] : max(0, $amountTotal - $amountUntaxed);
        $formatTotal = function($a) use ($currencySymbol) { return $currencySymbol . ' ' . number_format($a, 0, ',', '.'); };

        // Add convenient fields for UI
        $invoice['display_number'] = $displayNumber;
        $invoice['is_posted'] = $is_posted;
        $invoice['partner_details'] = $invoice['partner'] ?? null;
        $invoice['journal'] = $journal;
        $invoice['payment_term'] = $paymentTerm;
        $invoice['fiscal_position'] = $fiscal;
        $invoice['tax_summary'] = $taxSummary;
        $invoice['calculated_subtotal'] = $calculatedSubtotal;
        $invoice['calculated_subtotal_formatted'] = $formatTotal($calculatedSubtotal);
        $invoice['amount_untaxed'] = $amountUntaxed;
        $invoice['amount_untaxed_formatted'] = $formatTotal($amountUntaxed);
        $invoice['amount_tax'] = $amountTax;
        $invoice['amount_tax_formatted'] = $formatTotal($amountTax);
        $invoice['amount_total'] = $amountTotal;
        $invoice['amount_total_formatted'] = $formatTotal($amountTotal);
        $invoice['currency_code'] = $currencyCode ?: 'IDR';

        // Optional debug: include raw Odoo read outputs when debug=1
        $debugMode = !empty($_REQUEST['debug']);
        $response = [
            'success' => true,
            'action' => 'get_invoice',
            'data' => ['invoice' => $invoice, 'lines' => $lines]
        ];

        if ($debugMode) {
            $response['debug'] = [
                'raw_invoice' => $invoice,
                'raw_lines' => $raw ?? null,
                'odoo_read_diagnostics' => $odooReadDiagnostics
            ];
        }

        // Add a helpful warning when invoice has no lines
        if (empty($lines)) {
            $response['warning'] = 'Invoice has no lines; check Odoo raw data with debug=1';
        }

        $sendJson($response);
    }

    // Delete invoice (tries direct unlink, if fails, attempts draft+unlink)
    if ($action === 'delete_invoice') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? $_REQUEST;
        $inv_id = (int)($data['id'] ?? $_REQUEST['id'] ?? 0);
        if (!$inv_id) throw new Exception('Invoice ID required');

        try {
            // First attempt: direct unlink (best-case)
            $client->executePublic('account.move', 'unlink', [[$inv_id]]);
            $sendJson(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice deleted']);
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
                $sendJson(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice moved to draft and deleted']);
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
                $sendJson(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice canceled, moved to draft and deleted']);
                exit;
            } catch (Exception $e3) {
                $errors[] = 'action_cancel+...: ' . $e3->getMessage();
                error_log('Delete invoice action_cancel flow failed for '.$inv_id.': '.$e3->getMessage());
            }

            // Attempt 3: try writing state to draft (best-effort) then unlink
            try {
                $client->executePublic('account.move', 'write', [[$inv_id], ['state' => 'draft']]);
                $client->executePublic('account.move', 'unlink', [[$inv_id]]);
                $sendJson(['success' => true, 'action' => 'delete_invoice', 'message' => 'Invoice forced to draft and deleted']);
                exit;
            } catch (Exception $e4) {
                $errors[] = 'write+unlink: ' . $e4->getMessage();
                error_log('Delete invoice write+unlink failed for '.$inv_id.': '.$e4->getMessage());
            }

            // If all attempts failed, return combined errors
            $sendJson(['success' => false, 'action' => 'delete_invoice', 'error' => implode(' | ', $errors)], 500);
        }
    }

    // New action: find invoice(s) by order (search invoice_origin) — helpful when invoice_ids are missing
    if ($action === 'find_invoice_by_order') {
        $orderId = (int)($_REQUEST['id'] ?? 0);
        $type = $_REQUEST['type'] ?? 'purchase';
        if (!$orderId) throw new Exception('Order ID required');

        $model = ($type === 'sale') ? 'sale.order' : 'purchase.order';
        $order = $client->executePublic($model, 'read', [[$orderId], ['id','name']]);
        if (empty($order)) throw new Exception('Order not found');
        $order = $order[0];
        $name = $order['name'] ?? '';

        $invoices = $client->executePublic('account.move', 'search_read',
            [[['invoice_origin', 'ilike', $name]]],
            ['fields' => ['id', 'name']]
        );

        $sendJson(['success' => true, 'action' => 'find_invoice_by_order', 'data' => $invoices]);
    }

    // New action: create invoice (bill) from order lines (useful when Odoo didn't auto-create one)
    if ($action === 'create_invoice_from_order') {
        $po_id = (int)($_REQUEST['id'] ?? 0);
        $type = $_REQUEST['type'] ?? 'purchase'; // 'purchase' or 'sale'
        $postNow = !empty($_REQUEST['post_now']) ? true : false;
        if (!$po_id) throw new Exception('Order ID required');

        $model = ($type === 'sale') ? 'sale.order' : 'purchase.order';
        try {
            $order = $client->executePublic($model, 'read', [[$po_id], ['id','name','partner_id','order_line']]);
            if (empty($order)) throw new Exception('Order not found');
            $order = $order[0];

            $lines = [];
            if (!empty($order['order_line'])) {
                $lineItems = $client->executePublic($type === 'sale' ? 'sale.order.line' : 'purchase.order.line', 'read', [$order['order_line'], ['product_id','name','product_qty','price_unit','product_uom_qty']]);
                foreach ($lineItems as $li) {
                    $prod = $li['product_id'] ?? null;
                    $product_id = is_array($prod) ? (int)$prod[0] : (int)$prod;
                    $name = $li['name'] ?? (is_array($li['product_id']) ? ($li['product_id'][1] ?? 'Produk') : 'Produk');
                    $qty = (float)($li['product_qty'] ?? $li['product_uom_qty'] ?? 0);
                    $price = (float)($li['price_unit'] ?? 0);

                    $linePayload = [
                        'product_id' => $product_id > 0 ? $product_id : null,
                        'name' => $name,
                        'quantity' => $qty,
                        'price_unit' => $price
                    ];

                    // Attempt to resolve an appropriate account_id for the line (expense for bills, income for customer invoices)
                    if ($product_id > 0) {
                        try {
                            $p = $client->executePublic('product.product', 'read', [[$product_id]], ['fields' => ['id','name','product_tmpl_id','property_account_income_id','property_account_expense_id']]);
                            if (!empty($p) && !empty($p[0])) {
                                $pp = $p[0];
                                // Product-level configuration may include property_account_* fields
                                $acc = null;
                                if ($type === 'purchase') {
                                    if (!empty($pp['property_account_expense_id'])) $acc = is_array($pp['property_account_expense_id']) ? (int)$pp['property_account_expense_id'][0] : (int)$pp['property_account_expense_id'];
                                } else {
                                    if (!empty($pp['property_account_income_id'])) $acc = is_array($pp['property_account_income_id']) ? (int)$pp['property_account_income_id'][0] : (int)$pp['property_account_income_id'];
                                }

                                // If not found at product level, try product.template
                                if (empty($acc) && !empty($pp['product_tmpl_id'])) {
                                    $tmplId = is_array($pp['product_tmpl_id']) ? (int)$pp['product_tmpl_id'][0] : (int)$pp['product_tmpl_id'];
                                    if ($tmplId) {
                                        try {
                                            $tmpl = $client->executePublic('product.template', 'read', [[$tmplId]], ['fields' => ['property_account_income_id','property_account_expense_id']]);
                                            if (!empty($tmpl) && !empty($tmpl[0])) {
                                                $tt = $tmpl[0];
                                                if ($type === 'purchase' && !empty($tt['property_account_expense_id'])) $acc = is_array($tt['property_account_expense_id']) ? (int)$tt['property_account_expense_id'][0] : (int)$tt['property_account_expense_id'];
                                                if ($type === 'sale' && !empty($tt['property_account_income_id'])) $acc = is_array($tt['property_account_income_id']) ? (int)$tt['property_account_income_id'][0] : (int)$tt['property_account_income_id'];
                                            }
                                        } catch (Exception $e) {
                                            // ignore template lookup failures
                                        }
                                    }
                                }

                                if (!empty($acc)) {
                                    $linePayload['account_id'] = $acc;
                                }
                            }
                        } catch (Exception $e) {
                            // ignore product read errors
                        }
                    }

                    $lines[] = [0, 0, $linePayload];
                }
            }

            // Build invoice payload (no tax_ids per request)
            $invoice = [
                'partner_id' => is_array($order['partner_id']) ? (int)$order['partner_id'][0] : (int)$order['partner_id'],
                'move_type' => ($type === 'sale') ? 'out_invoice' : 'in_invoice',
                'invoice_date' => date('Y-m-d'),
                'invoice_line_ids' => $lines,
                'invoice_origin' => $order['name'] ?? null
            ];

            $invId = $client->executePublic('account.move', 'create', [$invoice]);
            if (is_array($invId)) $invId = $invId[0];

            $posted = false;
            if ($postNow && $invId) {
                try {
                    $client->executePublic('account.move', 'action_post', [[$invId]]);
                    $posted = true;
                } catch (Exception $e) {
                    error_log('Warning: failed to post invoice '.$invId.': '.$e->getMessage());
                }
            }

            // Read back the created invoice and its lines so UI can display real data
            $invObj = null;
            $invLines = [];
            $taxSummary = [];
            try {
                // Read comprehensive invoice fields (with fallback if some fields are missing)
                $fields = ['id','name','partner_id','invoice_date','invoice_date_due','amount_untaxed','amount_tax','amount_total','residual','state','move_type','invoice_line_ids','narration','invoice_origin','currency_id','journal_id','invoice_payment_term_id','fiscal_position_id','tax_line_ids'];
                try {
                    $invRead = $safeRead('account.move', [$invId], $fields);
                } catch (Exception $e) {
                    error_log('Warning: account.move read failed for created inv ' . $invId . ': ' . $e->getMessage());
                    $invRead = [];
                }

                if (!empty($invRead)) {
                    $invObj = $invRead[0];

                    // Tax summary (best-effort) — read move tax lines if present
                    if (!empty($invObj['tax_line_ids']) && is_array($invObj['tax_line_ids'])) {
                        try {
                            $taxLines = $client->executePublic('account.move.tax', 'read', [$invObj['tax_line_ids'], ['id','name','amount']]);
                            foreach ($taxLines as $t) {
                                $taxSummary[] = ['id' => (int)$t['id'], 'name' => $t['name'] ?? '', 'amount' => (float)($t['amount'] ?? 0)];
                            }
                        } catch (Exception $e) {
                            // some Odoo versions may not expose account.move.tax model, ignore
                        }
                    }

                    $fallback_used = false;
                    $rawLines = [];

                    // Primary: invoice_line_ids
                    if (!empty($invObj['invoice_line_ids'])) {
                        try {
                            $rawLines = $client->executePublic('account.move.line', 'read', [$invObj['invoice_line_ids'], ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
                        } catch (Exception $e) {
                            $rawLines = [];
                        }
                    }

                    // Fallback 1: some Odoo versions use 'line_ids'
                    if (empty($rawLines) && !empty($invObj['line_ids'])) {
                        try {
                            $rawLines = $client->executePublic('account.move.line', 'read', [$invObj['line_ids'], ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
                            if (!empty($rawLines)) $fallback_used = 'line_ids';
                        } catch (Exception $e) { $rawLines = []; }
                    }

                    // Fallback 2: search by move_id field on account.move.line
                    if (empty($rawLines) && !empty($invId)) {
                        try {
                            $rawLines = $client->executePublic('account.move.line', 'search_read', [[['move_id','=', $invId]]], ['fields' => ['id','product_id','name','quantity','price_unit','price_subtotal','account_id']]);
                            if (!empty($rawLines)) $fallback_used = 'search_by_move_id';
                        } catch (Exception $e) { $rawLines = []; }
                    }

                    // Enrich product info by batch-reading product.product
                    $productIds = [];
                    foreach ($rawLines as $rl) {
                        $p = $rl['product_id'] ?? null;
                        $pid = is_array($p) ? (int)$p[0] : (int)$p;
                        if ($pid) $productIds[$pid] = $pid;
                    }
                    $productsMap = [];
                    if (!empty($productIds)) {
                        try {
                            $prodData = $client->executePublic('product.product', 'read', [array_values($productIds), ['id','name','default_code']]);
                            foreach ($prodData as $pd) {
                                $productsMap[(int)$pd['id']] = $pd;
                            }
                        } catch (Exception $e) {
                            // ignore product enrich errors
                        }
                    }

                    // Build normalized lines
                    $formatAmount = function($a) {
                        return 'Rp ' . number_format((float)$a, 0, ',', '.');
                    };

                    foreach ($rawLines as $r) {
                        $prod = $r['product_id'] ?? null;
                        $prodId = is_array($prod) ? (int)$prod[0] : ((int)$prod ?: null);
                        $prodName = is_array($prod) ? ($prod[1] ?? null) : null;
                        if ($prodId && isset($productsMap[$prodId])) {
                            $prodName = $productsMap[$prodId]['name'] ?? $prodName;
                            $defaultCode = $productsMap[$prodId]['default_code'] ?? null;
                        } else {
                            $defaultCode = null;
                        }

                        $priceUnit = (float)($r['price_unit'] ?? 0);
                        $sub = (float)($r['price_subtotal'] ?? ($priceUnit * ($r['quantity'] ?? 0)));

                        $invLines[] = [
                            'id' => (int)$r['id'],
                            'product_id' => $prodId,
                            'product_name' => $prodName ?? $r['name'],
                            'product_sku' => $defaultCode,
                            'name' => $r['name'],
                            'quantity' => (float)($r['quantity'] ?? 0),
                            'price_unit' => $priceUnit,
                            'price_unit_formatted' => $formatAmount($priceUnit),
                            'price_subtotal' => $sub,
                            'price_subtotal_formatted' => $formatAmount($sub),
                            'account_id' => !empty($r['account_id']) && is_array($r['account_id']) ? (int)$r['account_id'][0] : ($r['account_id'] ?? null)
                        ];
                    }

                    if ($fallback_used) {
                        // attach note for diagnostics
                        $invObj['_fallback_used'] = $fallback_used;
                    }
                }
            } catch (Exception $e) {
                error_log('Warning: failed to read created invoice '.$invId.': '.$e->getMessage());
            }

            $debugMode = !empty($_REQUEST['debug']);
            $out = ['success' => true, 'action' => 'create_invoice_from_order', 'invoice_id' => $invId, 'invoice' => $invObj, 'lines' => $invLines, 'tax_summary' => $taxSummary, 'posted' => $posted];
            if ($debugMode) $out['debug'] = ['odoo_read_diagnostics' => $odooReadDiagnostics];
            $sendJson($out);
        } catch (Exception $e) {
            error_log('Error create_invoice_from_order: ' . $e->getMessage());
            $out = ['success' => false, 'action' => 'create_invoice_from_order', 'error' => $e->getMessage()];
            if (!empty($odooReadDiagnostics)) $out['odoo_read_diagnostics'] = $odooReadDiagnostics;
            if (!empty($_REQUEST['debug'])) $out['exception_trace'] = $e->getTraceAsString();
            $sendJson($out, 500);
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

        $sendJson([
            'success' => true,
            'action' => 'get_order_details',
            'data' => $lines
        ]);
    }

    // ==================== PRODUCTS ====================

    if ($action === 'list_products') {
        $products = $client->executePublic('product.product', 'search_read',
            [[]],
            ['fields' => ['id', 'name', 'list_price', 'standard_price', 'qty_available', 'categ_id']]
        );

        $sendJson([
            'success' => true,
            'action' => 'list_products',
            'count' => count($products),
            'data' => $products
        ]);
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

        $sendJson([
            'success' => true,
            'action' => 'create_product',
            'message' => 'Product created',
            'product_id' => $product_id
        ]);
    }

    // ==================== PARTNERS (Customers/Suppliers) ====================

    if ($action === 'list_partners') {
        $partners = [];
        try {
            $odooPartners = $client->executePublic('res.partner', 'search_read',
                [[]],
                ['fields' => ['id', 'name', 'email', 'phone', 'street', 'city', 'country_id']]
            );
            if (is_array($odooPartners)) {
                $partners = $odooPartners;
            }
        } catch (Exception $e) {
            error_log('Warning: list_partners Odoo fetch failed: ' . $e->getMessage());
        }

        // Append local users
        try {
            $db = $getDb();
            // Ensure necessary columns exist
            $havePhone = count($db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetchAll()) > 0;
            if (!$havePhone) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
            
            $haveOdooId = count($db->query("SHOW COLUMNS FROM users LIKE 'odoo_id'")->fetchAll()) > 0;
            if (!$haveOdooId) $db->exec("ALTER TABLE users ADD COLUMN odoo_id INT(11) NULL AFTER phone");

            $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
            if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
            $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
            if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

            $stmt = $db->query("SELECT id, name, email, phone, odoo_id, is_customer, is_supplier FROM users ORDER BY id DESC");
            $localUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Map local flags to Odoo IDs
            $localDataMap = [];
            foreach ($localUsers as $u) {
                if ($u['odoo_id']) {
                    $localDataMap[(int)$u['odoo_id']] = $u;
                }
            }

            // Enrich Odoo partners with local flags
            foreach ($partners as &$s) {
                if (isset($localDataMap[(int)$s['id']])) {
                    $lu = $localDataMap[(int)$s['id']];
                    $s['is_customer'] = (bool)$lu['is_customer'];
                    $s['is_supplier'] = (bool)$lu['is_supplier'];
                } else {
                    $s['is_customer'] = false;
                    $s['is_supplier'] = false;
                }
            }
            unset($s);

            // Map existing Odoo IDs for deduplication
            $existingOdooIds = array_filter(array_column($partners, 'id'), 'is_numeric');

            foreach ($localUsers as $u) {
                // Skip if this local user is already linked to an Odoo partner and that partner is in the list
                if ($u['odoo_id'] && in_array((int)$u['odoo_id'], $existingOdooIds)) {
                    continue; 
                }

                $partners[] = [
                    'id' => 'local-' . $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'phone' => $u['phone'],
                    'street' => '',
                    'city' => '',
                    'country_id' => false,
                    'is_customer' => (bool)$u['is_customer'],
                    'is_supplier' => (bool)$u['is_supplier'],
                    'customer_rank' => $u['is_customer'] ? 1 : 0,
                    'supplier_rank' => $u['is_supplier'] ? 1 : 0,
                    'odoo_id' => $u['odoo_id'],
                    'local' => true
                ];
            }
        } catch (Exception $e) {
            error_log('Warning: list_partners local fetch failed: ' . $e->getMessage());
        }

        $sendJson([
            'success' => true,
            'action' => 'list_partners',
            'count' => count($partners),
            'data' => $partners
        ]);
    }

    // Unified: create a partner (can be customer and/or supplier)
    if ($action === 'create_partner') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $name = trim($data['name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $is_customer = (!empty($data['is_customer']) || !empty($data['customer'])) ? 1 : 0;
        $is_supplier = (!empty($data['is_supplier']) || !empty($data['supplier'])) ? 1 : 0;
        $saveTo = trim($data['save_to'] ?? ($data['saveTo'] ?? 'odoo'));

        if (!$name) throw new Exception('Name required');

        // If saving to Odoo, try to find existing partner by email or name and update ranks
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
                error_log('Warning: create_partner Odoo lookup failed: ' . $e->getMessage());
            }

            if ($partner_id) {
                try {
                    $update = [];
                    // Ranks removed for Odoo 17+ compatibility
                    if (!empty($update)) {
                        $client->executePublic('res.partner', 'write', [[$partner_id], $update]);
                    }
                } catch (Exception $e) {
                    error_log('Warning: create_partner Odoo write failed: ' . $e->getMessage());
                }
            } else {
                try {
                    $partner_data = ['name' => $name, 'email' => $email, 'phone' => $phone];
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
            $db = $getDb();

            // Ensure columns exist
            $havePhone = count($db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetchAll()) > 0;
            if (!$havePhone) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
            
            $haveOdooId = count($db->query("SHOW COLUMNS FROM users LIKE 'odoo_id'")->fetchAll()) > 0;
            if (!$haveOdooId) $db->exec("ALTER TABLE users ADD COLUMN odoo_id INT(11) NULL AFTER phone");

            $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
            $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
            if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
            if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

            $stmt = $db->prepare('INSERT INTO users (name, email, phone, odoo_id, is_customer, is_supplier) VALUES (:name, :email, :phone, :oid, :c, :s)');
            $stmt->execute([':name'=>$name,':email'=>$email,':phone'=>$phone,':oid'=>$partner_id,':c'=>$is_customer,':s'=>$is_supplier]);
            
            $localId = (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log('Warning: create_partner local save failed: '.$e->getMessage());
            $localId = null;
        }

        $sendJson(['success'=>true,'action'=>'create_partner','message'=>'Partner created','id'=>$localId,'partner_id'=>$partner_id,'name'=>$name,'is_customer'=>$is_customer,'is_supplier'=>$is_supplier]);
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
                $db = $getDb();

                // Ensure columns exist
                $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
                $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
                if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
                if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

                $u = $db->prepare('UPDATE users SET is_customer = ?, is_supplier = ? WHERE id = ?');
                $u->execute([$is_customer, $is_supplier, $localId]);

                $sendJson(['success'=>true,'action'=>'update_partner_roles','message'=>'Local partner roles updated']);
                exit;
            } catch (Exception $e) {
                throw new Exception('Failed to update local partner: ' . $e->getMessage());
            }
        }

        // Odoo partner
        $partnerId = (int)$rawId;
        if (!$partnerId) throw new Exception('Invalid partner id');
        try {
            // Save roles to local DB for Odoo partners (Odoo 17+ doesn't use ranks anymore)
            $db = $getDb();
            // Ensure columns exist
            $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
            $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
            if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");
            if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

            $stmt = $db->prepare("SELECT id FROM users WHERE odoo_id = ?");
            $stmt->execute([$partnerId]);
            $local = $stmt->fetch();

            if ($local) {
                $stmt = $db->prepare("UPDATE users SET is_customer = ?, is_supplier = ? WHERE id = ?");
                $stmt->execute([(int)$is_customer, (int)$is_supplier, $local['id']]);
            } else {
                // Fetch name from Odoo to create local record
                try {
                    $partnerDetails = $client->executePublic('res.partner', 'read', [[$partnerId]], ['fields' => ['name', 'email', 'phone']]);
                    if ($partnerDetails && count($partnerDetails) > 0) {
                        $pd = $partnerDetails[0];
                        $stmt = $db->prepare("INSERT INTO users (name, email, phone, odoo_id, is_customer, is_supplier) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $pd['name'] ?? 'Odoo Partner',
                            ($pd['email'] !== false) ? $pd['email'] : null,
                            ($pd['phone'] !== false) ? $pd['phone'] : null,
                            $partnerId,
                            (int)$is_customer,
                            (int)$is_supplier
                        ]);
                    }
                } catch (Exception $e) {}
            }

            $sendJson(['success'=>true,'action'=>'update_partner_roles','message'=>'Partner roles updated locally']);
        } catch (Exception $e) {
            throw new Exception('Failed to update partner: ' . $e->getMessage());
        }
    }

    if ($action === 'sync_products') {
        try {
            // Fetch both saleable and purchasable products
            $products = $client->executePublic('product.product', 'search_read', 
                [['|', ['sale_ok', '=', true], ['purchase_ok', '=', true]]], 
                ['fields' => ['id', 'name', 'list_price', 'standard_price', 'qty_available', 'description']]
            );
            
            if (is_array($products)) {
                $db = $getDb();
                $count = 0;
                $odooIdsFound = [];

                foreach ($products as $p) {
                    $odooIdsFound[] = (int)$p['id'];
                    $price = isset($p['list_price']) ? (float)$p['list_price'] : 0;
                    $stock = isset($p['qty_available']) ? (float)$p['qty_available'] : 0;
                    $name = $p['name'] ?? 'Odoo Product';

                    // 1. Try match by odoo_id
                    $stmt = $db->prepare("SELECT id FROM inventory WHERE odoo_id = ?");
                    $stmt->execute([$p['id']]);
                    $local = $stmt->fetch();

                    if (!$local) {
                        // 2. Try match by Exact Name (if not linked)
                        $stmt = $db->prepare("SELECT id FROM inventory WHERE name = ? AND odoo_id IS NULL");
                        $stmt->execute([$name]);
                        $local = $stmt->fetch();
                    }

                    if ($local) {
                        $stmt = $db->prepare("UPDATE inventory SET name = ?, selling_price = ?, quantity = ?, odoo_id = ? WHERE id = ?");
                        $stmt->execute([$name, $price, $stock, $p['id'], $local['id']]);
                    } else {
                        // Fix: use selling_price and category (string) instead of non-existent columns
                        $stmt = $db->prepare("INSERT INTO inventory (name, selling_price, quantity, odoo_id, category) VALUES (?, ?, ?, ?, 'Uncategorized')");
                        $stmt->execute([$name, $price, $stock, $p['id']]);
                    }
                    $count++;
                }

                // 3. Cleanup broken links: NULL out odoo_id for records not found in Odoo
                // This prevents 'Record does not exist' errors when creating orders
                if (!empty($odooIdsFound)) {
                    $placeholders = implode(',', array_fill(0, count($odooIdsFound), '?'));
                    $sql = "UPDATE inventory SET odoo_id = NULL WHERE odoo_id IS NOT NULL AND odoo_id NOT IN ($placeholders)";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($odooIdsFound);
                }

                // 4. Two-Way Sync: Push Unlinked Local Products to Odoo
                // Check for items with NULL or 0 odoo_id and push them
                $stmt = $db->query("SELECT id, name, sku, selling_price, cost_price FROM inventory WHERE odoo_id IS NULL OR odoo_id = 0");
                $unlinked = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $foundUnlinked = count($unlinked);
                $pushed = 0;
                $pushErrors = [];
                $pushedMapping = [];

                foreach ($unlinked as $u) {
                    try {
                        $prodData = [
                            'name' => $u['name'],
                            'list_price' => (float)($u['selling_price'] ?? 0),
                            'standard_price' => (float)($u['cost_price'] ?? 0),
                            'type' => 'product',
                            'purchase_ok' => true,
                            'sale_ok' => true,
                        ];
                        if (!empty($u['sku'])) $prodData['default_code'] = $u['sku'];

                        $newProductId = $client->executePublic('product.product', 'create', [$prodData]);
                        if (is_array($newProductId)) $newProductId = (int)$newProductId[0];
                        $newProductId = (int)$newProductId;

                        if ($newProductId > 0) {
                            $upd = $db->prepare("UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1, last_sync_at = NOW() WHERE id = ?");
                            $upd->execute([$newProductId, $u['id']]);
                            $pushed++;
                            $pushedMapping[] = ['local_id' => $u['id'], 'odoo_id' => $newProductId];
                        } else {
                            $pushErrors[] = "Invalid create response for {$u['name']}: " . var_export($newProductId, true);
                        }
                    } catch (Exception $e) {
                        $pushErrors[] = "Failed to push {$u['name']}: " . $e->getMessage();
                        error_log("Failed to push product {$u['name']} to Odoo: " . $e->getMessage());
                    }
                }

                $sendJson(['success' => true, 'message' => "Synced $count products from Odoo.", 'found_unlinked' => $foundUnlinked, 'pushed' => $pushed, 'pushed_map' => $pushedMapping, 'push_errors' => $pushErrors]);
            } else {
                $sendJson(['success' => false, 'message' => 'Failed to fetch products from Odoo'], 500);
            }
        } catch (Exception $e) {
            $sendJson(['success' => false, 'message' => $e->getMessage()], 500);
        }
        exit;
    }

    if ($action === 'list_suppliers') {
        $suppliers = [];
        // Try to fetch suppliers from Odoo first (best-effort)
        try {
                // Prefer domain filtering by supplier_rank (>0). Avoid requesting non-existent 'supplier' field which can raise errors in some Odoo versions.
                try {
                    $odooSuppliers = $client->executePublic('res.partner', 'search_read',
                        [[['supplier_rank', '>', 0]]],
                        ['fields' => ['id', 'name', 'email', 'phone', 'supplier_rank']]
                    );
                } catch (Exception $domainErr) {
                    // Domain search may fail on some installs; fall back to fetching all partners and filter locally by supplier_rank
                    error_log('Warning: supplier domain query failed, falling back to full partner fetch: ' . $domainErr->getMessage());
                    $allPartners = $client->executePublic('res.partner', 'search_read',
                        [[]],
                        ['fields' => ['id', 'name', 'email', 'phone', 'supplier_rank']]
                    );
                    $odooSuppliers = [];
                    if (is_array($allPartners)) {
                        foreach ($allPartners as $p) {
                            if ((isset($p['supplier_rank']) && $p['supplier_rank'] > 0)) {
                        }
                    }
                }
            }

            if (is_array($odooSuppliers)) $suppliers = $odooSuppliers;
        } catch (Exception $e) {
            // log and continue — we'll still include local suppliers
            error_log('Warning: list_suppliers Odoo fetch failed: ' . $e->getMessage());
        }

        // Append local users (so suppliers created locally appear in lists)
        try {
            $db = $getDb();
            // Ensure necessary columns exist
            $havePhone = count($db->query("SHOW COLUMNS FROM users LIKE 'phone'")->fetchAll()) > 0;
            if (!$havePhone) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
            
            $haveSupplier = count($db->query("SHOW COLUMNS FROM users LIKE 'is_supplier'")->fetchAll()) > 0;
            if (!$haveSupplier) $db->exec("ALTER TABLE users ADD COLUMN is_supplier TINYINT(1) DEFAULT 0");

            // Filter for suppliers only (FIX: is_supplier = 1)
            $stmt = $db->query("SELECT id, name, email, phone, is_customer, is_supplier FROM users WHERE is_supplier = 1 ORDER BY id DESC");
            $localUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($localUsers as $u) {
                // mark local suppliers with id prefix so caller can distinguish
                $suppliers[] = [
                    'id' => 'local-' . $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'phone' => $u['phone'] ?? null,
                    'is_customer' => (bool)($u['is_customer'] ?? 0),
                    'is_supplier' => (bool)($u['is_supplier'] ?? 1),
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

        $sendJson([
            'success' => true,
            'action' => 'list_suppliers',
            'count' => count($dedup),
            'data' => $dedup
        ]);
    }

    if ($action === 'list_customers') {
        $customers = [];
        try {
            $odooCustomers = $client->executePublic('res.partner', 'search_read',
                [[]],
                ['fields' => ['id', 'name', 'email', 'phone', 'city']]
            );
            if (is_array($odooCustomers)) $customers = $odooCustomers;
        } catch (Exception $e) {
            error_log('Warning: list_customers Odoo fetch failed: ' . $e->getMessage());
        }

        // Append local users as customers (id prefixed with local-)
        try {
            $db = $getDb();
            // Ensure necessary columns exist
            $haveCustomer = count($db->query("SHOW COLUMNS FROM users LIKE 'is_customer'")->fetchAll()) > 0;
            if (!$haveCustomer) $db->exec("ALTER TABLE users ADD COLUMN is_customer TINYINT(1) DEFAULT 0");

            // Filter for customers only (FIX: is_customer = 1)
            $stmt = $db->query("SELECT id, name, email, phone, is_customer, is_supplier FROM users WHERE is_customer = 1 ORDER BY id DESC");
            $localUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($localUsers as $u) {
                $customers[] = [
                    'id' => 'local-' . $u['id'],
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'phone' => $u['phone'] ?? null,
                    'is_customer' => (bool)($u['is_customer'] ?? 1),
                    'is_supplier' => (bool)($u['is_supplier'] ?? 0),
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

        $sendJson([
            'success' => true,
            'action' => 'list_customers',
            'count' => count($dedup),
            'data' => $dedup
        ]);
    }

    if ($action === 'create_partner') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $partner_data = [
            'name' => $data['name'] ?? throw new Exception('Partner name required'),
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'is_company' => (bool)($data['is_company'] ?? true)
        ];

        if (isset($data['supplier'])) { /* $partner_data['supplier_rank'] = (int)$data['supplier']; */ }
        if (isset($data['customer'])) { /* $partner_data['customer_rank'] = (int)$data['customer']; */ }

        $partner_id = $client->executePublic('res.partner', 'create', [$partner_data]);

        $sendJson([
            'success' => true,
            'action' => 'create_partner',
            'message' => 'Partner created',
            'partner_id' => $partner_id
        ]);
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
                'name' => $name
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

        $sendJson([
            'success' => true,
            'action' => 'create_customer',
            'message' => 'Customer created locally',
            'id' => $localId,
            'name' => $name,
            'partner_id' => $partner_id
        ]);
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

                $sendJson(['success' => true, 'action' => 'delete_customer', 'message' => 'Local customer deleted']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete local customer: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid customer id');
            try {
                // Changing unlink (delete) to archive for better compatibility with Odoo constraints
                $client->executePublic('res.partner', 'write', [[$partnerId], ['active' => false]]);
                $sendJson(['success' => true, 'action' => 'delete_customer', 'message' => 'Odoo partner archived']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete (archive) Odoo partner: ' . $e->getMessage());
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
                $sendJson(['success' => true, 'action' => 'create_supplier', 'message' => 'Supplier already exists locally', 'id' => (int)$existing['id'], 'name' => $existing['name'], 'partner_id' => null]);
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
                $partner_data = ['name' => $name, 'email' => $email, 'phone' => $phone, 'is_company' => true];
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

        $sendJson([
            'success' => true,
            'action' => 'create_supplier',
            'message' => 'Supplier created',
            'id' => $localId,
            'name' => $name,
            'partner_id' => $partner_id
        ]);
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

                $sendJson(['success' => true, 'action' => 'delete_supplier', 'message' => 'Local supplier deleted']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete local supplier: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid supplier id');
            try {
                // Changing unlink (delete) to archive for better compatibility with Odoo constraints
                $client->executePublic('res.partner', 'write', [[$partnerId], ['active' => false]]);
                $sendJson(['success' => true, 'action' => 'delete_supplier', 'message' => 'Odoo supplier archived']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete (archive) Odoo supplier: ' . $e->getMessage());
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

                $sendJson(['success' => true, 'action' => 'delete_partner', 'message' => 'Local partner deleted']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete local partner: ' . $e->getMessage());
            }
        } else {
            // Assume Odoo partner id
            $partnerId = (int)$rawId;
            if (!$partnerId) throw new Exception('Invalid partner id');
            try {
                // Changing unlink (delete) to archive for better compatibility with Odoo constraints
                $client->executePublic('res.partner', 'write', [[$partnerId], ['active' => false]]);
                $sendJson(['success' => true, 'action' => 'delete_partner', 'message' => 'Odoo partner archived']);
            } catch (Exception $e) {
                throw new Exception('Failed to delete (archive) Odoo partner: ' . $e->getMessage());
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
        
        $sendJson([
            'success' => true,
            'action' => 'cleanup_duplicate_partners',
            'deleted_count' => $deletedCount,
            'archived_count' => $archivedCount,
            'total_analyzed' => count($partners),
            'message' => "Cleanup: $deletedCount deleted, $archivedCount archived. " . (count($errors) > 0 ? "Errors: " . implode("; ", array_slice($errors, 0, 3)) : "")
        ]);
    }

    if ($action === 'get_product') {
        $product_id = (int)($_REQUEST['id'] ?? 0);
        if (!$product_id) throw new Exception('Product ID required');

        $product = $client->executePublic('product.product', 'read', [[$product_id]],
            ['fields' => ['id', 'name', 'default_code', 'list_price', 'standard_price', 'qty_available', 'virtual_available', 'incoming_qty', 'outgoing_qty', 'categ_id', 'barcode', 'type', 'description']]
        );

        if (!$product) throw new Exception('Product not found');

        $sendJson([
            'success' => true,
            'action' => 'get_product',
            'data' => $product[0]
        ]);
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

        $sendJson([
            'success' => true,
            'action' => 'update_product',
            'message' => 'Product updated',
            'product_id' => $product_id
        ]);
    }

    if ($action === 'delete_product') {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $product_id = (int)($data['id'] ?? $_REQUEST['id'] ?? 0);
        if (!$product_id) throw new Exception('Product ID required');

        $client->executePublic('product.product', 'unlink', [[$product_id]]);

        $sendJson([
            'success' => true,
            'action' => 'delete_product',
            'message' => 'Product deleted',
            'product_id' => $product_id
        ]);
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

        $sendJson([
            'success' => true,
            'action' => 'update_stock',
            'message' => 'Stock updated',
            'product_id' => $product_id,
            'new_quantity' => $new_quantity
        ]);
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

        $sendJson([
            'success' => true,
            'action' => 'get_stock_movements',
            'count' => count($movements),
            'data' => $movements
        ]);
    }

    if ($action === 'search_products') {
        $query = $_REQUEST['q'] ?? '';
        if (strlen($query) < 2) throw new Exception('Search query too short (minimum 2 characters)');

        $products = $client->executePublic('product.product', 'search_read',
            [[['name', 'ilike', $query]]],
            ['fields' => ['id', 'name', 'default_code', 'list_price', 'qty_available'], 'limit' => 20]
        );

        $sendJson([
            'success' => true,
            'action' => 'search_products',
            'count' => count($products),
            'data' => $products
        ]);
    }

    // ==================== LOCAL INVENTORY ====================

    if ($action === 'create_inventory') {
        $db = $getDb();
        $name = trim($_POST['name'] ?? '');
        if (!$name) throw new Exception('Product name required');

        // Sanitize numeric inputs (allow formatted inputs like "1.000.000" or "1,000,000")
        $sanitize = function($v) {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            // Remove common currency symbols and grouping separators
            $v = str_replace(['Rp', ' ', '\u00A0', ',', ' '], ['', '', '', '', ''], $v);
            // Remove any character that's not digit, dot, or minus
            $v = preg_replace('/[^0-9.\-]/', '', $v);
            if ($v === '' || $v === '-' || $v === '.' || $v === '-.') return 0.0;
            return (float)$v;
        };

        $quantity = $sanitize($_POST['quantity'] ?? 0);
        $selling_price = $sanitize($_POST['selling_price'] ?? 0);
        $cost_price = $sanitize($_POST['cost_price'] ?? 0);

        // Validate ranges that fit into DECIMAL(10,2) (max 99,999,999.99)
        $MAX_DECIMAL = 99999999.99;
        if (!is_finite($selling_price) || abs($selling_price) > $MAX_DECIMAL) throw new Exception('Selling price is out of allowed range (max ' . number_format($MAX_DECIMAL,2,'.',',') . ')');
        if (!is_finite($cost_price) || abs($cost_price) > $MAX_DECIMAL) throw new Exception('Cost price is out of allowed range (max ' . number_format($MAX_DECIMAL,2,'.',',') . ')');
        if (!is_finite($quantity) || abs($quantity) > 999999999) throw new Exception('Quantity is out of allowed range');

        $stmt = $db->prepare("INSERT INTO inventory (name, description, quantity, category, selling_price, cost_price, sku, product_type, status, synced_to_odoo, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, NOW(), NOW())");
        $stmt->execute([
            $name,
            $_POST['description'] ?? null,
            $quantity,
            trim($_POST['category'] ?? '') ?: 'Uncategorized',
            $selling_price,
            $cost_price,
            trim($_POST['sku'] ?? '') ?: null,
            $_POST['product_type'] ?? 'product'
        ]);

        $localId = (int)$db->lastInsertId();
        $pushResult = ['pushed' => false, 'odoo_id' => null, 'error' => null];

        // Try to immediately create product in Odoo so user doesn't need manual sync
        try {
            // 0. Try to find an existing product in Odoo by SKU (default_code) or by name to avoid duplicates
            $foundId = null;
            $sku = trim($_POST['sku'] ?? '');
            if ($sku !== '') {
                try {
                    $found = $client->executePublic('product.product', 'search_read', [[['default_code', '=', $sku]]], ['fields' => ['id']]);
                    if (!empty($found) && is_array($found)) {
                        $foundId = (int)$found[0]['id'];
                        $pushResult['note'] = 'Matched by SKU';
                    }
                } catch (Exception $e) {
                    // ignore lookup errors and continue
                }
            }

            if (!$foundId) {
                // try by exact name
                try {
                    $found = $client->executePublic('product.product', 'search_read', [[['name', '=', $name]]], ['fields' => ['id']]);
                    if (!empty($found) && is_array($found)) {
                        $foundId = (int)$found[0]['id'];
                        $pushResult['note'] = 'Matched by name';
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }

            if ($foundId) {
                // Use existing product id
                $upd = $db->prepare("UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1, last_sync_at = NOW() WHERE id = ?");
                $upd->execute([$foundId, $localId]);
                $pushResult['pushed'] = true;
                $pushResult['odoo_id'] = $foundId;
            } else {
                $prodData = [
                    'name' => $name,
                    'list_price' => (float)($_POST['selling_price'] ?? 0),
                    'standard_price' => (float)($_POST['cost_price'] ?? 0),
                    'type' => $_POST['product_type'] ?? 'product',
                    'purchase_ok' => true,
                    'sale_ok' => true,
                ];
                if ($sku !== '') $prodData['default_code'] = $sku;

                $newProductId = $client->executePublic('product.product', 'create', [$prodData]);
                if (is_array($newProductId)) $newProductId = (int)$newProductId[0];
                $newProductId = (int)$newProductId;

                if ($newProductId > 0) {
                    $upd = $db->prepare("UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1, last_sync_at = NOW() WHERE id = ?");
                    $upd->execute([$newProductId, $localId]);
                    $pushResult['pushed'] = true;
                    $pushResult['odoo_id'] = $newProductId;
                    $pushResult['note'] = 'Created in Odoo';
                } else {
                    $pushResult['error'] = 'Invalid response from Odoo create';
                }
            }
        } catch (Exception $e) {
            $pushResult['error'] = $e->getMessage();
            error_log('Failed to auto-push product to Odoo: ' . $e->getMessage());

            // Write debug file for deeper inspection (contains product data and exception trace)
            $tempDir = dirname(__DIR__, 2) . '/temp';
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $debugFile = $tempDir . '/odoo_push_error_local_' . $localId . '_' . date('Ymd_His') . '.log';
            $debugPayload = [
                'time' => date('c'),
                'local_id' => $localId,
                'submitted' => [
                    'name' => $name,
                    'sku' => $sku,
                    'selling_price' => $selling_price,
                    'cost_price' => $cost_price,
                    'product_type' => $_POST['product_type'] ?? null
                ],
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            file_put_contents($debugFile, json_encode($debugPayload, JSON_PRETTY_PRINT));
            $pushResult['log_file'] = basename($debugFile);
        }

        $sendJson(['success' => true, 'message' => 'Product created in local inventory', 'id' => $localId, 'odoo_push' => $pushResult]);
    }

    // Allow pushing an existing local product to Odoo (retry) -> returns odoo_push diagnostics
    if ($action === 'push_inventory') {
        $db = $getDb();
        $id = (int)($_REQUEST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');
        $row = $db->prepare("SELECT * FROM inventory WHERE id = ?");
        $row->execute([$id]);
        $product = $row->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new Exception('Product not found');

        $pushResult = ['pushed' => false, 'odoo_id' => null, 'error' => null, 'note' => null];

        try {
            $foundId = null;
            $sku = trim($product['sku'] ?? '');
            if ($sku !== '') {
                try {
                    $found = $client->executePublic('product.product', 'search_read', [[['default_code', '=', $sku]]], ['fields' => ['id']]);
                    if (!empty($found) && is_array($found)) {
                        $foundId = (int)$found[0]['id'];
                        $pushResult['note'] = 'Matched by SKU';
                    }
                } catch (Exception $e) { }
            }

            if (!$foundId) {
                try {
                    $found = $client->executePublic('product.product', 'search_read', [[['name', '=', $product['name']]]], ['fields' => ['id']]);
                    if (!empty($found) && is_array($found)) {
                        $foundId = (int)$found[0]['id'];
                        $pushResult['note'] = 'Matched by name';
                    }
                } catch (Exception $e) { }
            }

            if ($foundId) {
                $upd = $db->prepare("UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1, last_sync_at = NOW() WHERE id = ?");
                $upd->execute([$foundId, $id]);
                $pushResult['pushed'] = true;
                $pushResult['odoo_id'] = $foundId;
            } else {
                $prodData = [
                    'name' => $product['name'],
                    'list_price' => (float)$product['selling_price'],
                    'standard_price' => (float)$product['cost_price'],
                    'type' => $product['product_type'] ?: 'product',
                    'purchase_ok' => true,
                    'sale_ok' => true,
                ];
                if ($sku !== '') $prodData['default_code'] = $sku;

                $newProductId = $client->executePublic('product.product', 'create', [$prodData]);
                if (is_array($newProductId)) $newProductId = (int)$newProductId[0];
                $newProductId = (int)$newProductId;

                if ($newProductId > 0) {
                    $upd = $db->prepare("UPDATE inventory SET odoo_id = ?, synced_to_odoo = 1, last_sync_at = NOW() WHERE id = ?");
                    $upd->execute([$newProductId, $id]);
                    $pushResult['pushed'] = true;
                    $pushResult['odoo_id'] = $newProductId;
                    $pushResult['note'] = 'Created in Odoo';
                } else {
                    $pushResult['error'] = 'Invalid response from Odoo create';
                }
            }
        } catch (Exception $e) {
            $pushResult['error'] = $e->getMessage();
            error_log('Failed to push product to Odoo: ' . $e->getMessage());

            // Save debug file for inspection
            $tempDir = dirname(__DIR__, 2) . '/temp';
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $debugFile = $tempDir . '/odoo_push_error_push_' . $id . '_' . date('Ymd_His') . '.log';
            $debugPayload = [
                'time' => date('c'),
                'local_id' => $id,
                'product' => $product,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            file_put_contents($debugFile, json_encode($debugPayload, JSON_PRETTY_PRINT));
            $pushResult['log_file'] = basename($debugFile);
        }

        $sendJson(['success' => true, 'id' => $id, 'odoo_push' => $pushResult]);
    }

    if ($action === 'edit_inventory') {
        $db = $getDb();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) throw new Exception('ID required');

        $fields = [];
        $params = [];
        $updatable = ['name', 'description', 'category', 'selling_price', 'cost_price', 'quantity', 'sku', 'product_type'];

        // Helper to sanitize numbers (same as create)
        $sanitize = function($v) {
            $v = trim((string)$v);
            if ($v === '') return 0.0;
            $v = str_replace(['Rp', ' ', '\u00A0', ',', ' '], ['', '', '', '', ''], $v);
            $v = preg_replace('/[^0-9.\-]/', '', $v);
            if ($v === '' || $v === '-' || $v === '.' || $v === '-.') return 0.0;
            return (float)$v;
        };

        foreach ($updatable as $field) {
            if (isset($_POST[$field])) {
                if (in_array($field, ['selling_price','cost_price'])) {
                    $val = $sanitize($_POST[$field]);
                    // Validate range
                    $MAX_DECIMAL = 99999999.99;
                    if (!is_finite($val) || abs($val) > $MAX_DECIMAL) throw new Exception(ucfirst(str_replace('_',' ',$field))." is out of allowed range (max " . number_format($MAX_DECIMAL,2,'.',',') . ")");
                    $fields[] = "$field = ?";
                    $params[] = $val;
                } elseif ($field === 'quantity') {
                    $val = $sanitize($_POST[$field]);
                    if (!is_finite($val) || abs($val) > 999999999) throw new Exception('Quantity is out of allowed range');
                    $fields[] = "$field = ?";
                    $params[] = $val;
                } else {
                    $fields[] = "$field = ?";
                    $params[] = $_POST[$field];
                }
            }
        }

        if (empty($fields)) throw new Exception('No fields to update');

        $params[] = $id;
        $sql = "UPDATE inventory SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $sendJson(['success' => true, 'message' => 'Local inventory updated']);
    }

    // ==================== ERROR HANDLING ====================

    throw new Exception("Unknown action: {$action}");

} catch (Exception $e) {
    // Ensure there is no stray output before sending JSON (prevents client 'unexpected token' errors)
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    $sendJson([
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $_REQUEST['action'] ?? 'unknown'
    ], 400);
}
?>
