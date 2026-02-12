<?php

namespace App\Controllers;

use App\Library\OdooClient;

class OdooInvoicingController extends OdooControllerBase
{
    /**
     * List invoices
     */
    public function indexAction()
    {
        try {
            if (!$this->odoo) {
                throw new \Exception("Odoo connection not available. Check your configuration.");
            }

            // Optional partner filter via query param
            $partnerId = (int)$this->request->getQuery('partner_id');

            // Prefer a broad set of invoice-like move_types, but fall back if Odoo fields/versions differ
            $preferredTypes = ['out_invoice', 'in_invoice', 'out_refund', 'in_refund'];
            $domain = [['move_type', 'in', $preferredTypes]];
            if ($partnerId) {
                // If partner provided, strict filter
                $domain[] = ['partner_id', '=', $partnerId];
            }

            // Primary attempt: domain with preferred types
            $fetchDebug = [];
            try {
                $invoices = $this->odoo->executePublic('account.move', 'search_read',
                    [$domain],
                    ['fields' => ['id', 'name', 'partner_id', 'invoice_date', 'amount_total', 'state', 'move_type'], 'limit' => 250]
                );
                $fetchDebug[] = ['step' => 'primary', 'success' => true, 'count' => is_array($invoices) ? count($invoices) : 0];
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                $fetchDebug[] = ['step' => 'primary', 'success' => false, 'error' => $errorMessage];
                error_log('Warning: invoice primary domain fetch failed: ' . $errorMessage);
                
                if (stripos($errorMessage, 'Access Denied') !== false || stripos($errorMessage, 'permission') !== false) {
                    $this->flash->warning("⚠️ Akun Odoo Anda tidak memiliki izin untuk mengakses modul Invoicing (account.move). Hal ini sering terjadi jika user Anda bukan 'Accounting / Billing' di Odoo.");
                    $this->view->accessDenied = true;
                }
                
                $invoices = [];
            }

            // Fallback 1: try the simpler in/out invoices only
            if (empty($invoices)) {
                try {
                    $domain2 = [['move_type', 'in', ['out_invoice', 'in_invoice']]];
                    if ($partnerId) $domain2[] = ['partner_id', '=', $partnerId];
                    $invoices = $this->odoo->executePublic('account.move', 'search_read',
                        [$domain2],
                        ['fields' => ['id', 'name', 'partner_id', 'invoice_date', 'amount_total', 'state', 'move_type'], 'limit' => 250]
                    );
                    if (!empty($invoices)) {
                        $fetchDebug[] = ['step' => 'fallback1', 'success' => true, 'count' => count($invoices)];
                        error_log('Notice: invoice fetch succeeded with simpler domain');
                    } else {
                        $fetchDebug[] = ['step' => 'fallback1', 'success' => true, 'count' => 0];
                    }
                } catch (\Throwable $e) {
                    error_log('Warning: invoice fallback domain fetch failed: ' . $e->getMessage());
                    $invoices = [];
                }
            }

            // Fallback 2: fetch recent moves and filter client-side (best-effort) to handle custom/missing fields
            if (empty($invoices)) {
                try {
                    $all = $this->odoo->executePublic('account.move', 'search_read',
                        [[]],
                        ['fields' => ['id','name', 'partner_id', 'invoice_date', 'amount_total', 'state', 'move_type'], 'limit' => 500]
                    );
                    $filtered = [];
                    if (is_array($all)) {
                        foreach ($all as $m) {
                            $mt = $m['move_type'] ?? '';
                            // Using strpos for compatibility with PHP < 8.0
                            if ($mt && (strpos((string)$mt, 'invoice') !== false || strpos((string)$mt, 'refund') !== false)) {
                                if ($partnerId) {
                                    $pid = is_array($m['partner_id']) ? (int)$m['partner_id'][0] : (int)$m['partner_id'];
                                    if ($pid !== $partnerId) continue;
                                }
                                $filtered[] = $m;
                            }
                        }
                    }
                    $invoices = array_values($filtered);
                    if (!empty($invoices)) {
                        $fetchDebug[] = ['step' => 'fallback2', 'success' => true, 'count' => count($invoices)];
                        error_log('Notice: invoice fetch succeeded via fallback client-side filter');
                    } else {
                        $fetchDebug[] = ['step' => 'fallback2', 'success' => true, 'count' => 0];
                    }
                } catch (\Throwable $e) {
                    error_log('Warning: invoice final fallback fetch failed: ' . $e->getMessage());
                    $invoices = [];
                }
            }
            
            $this->view->invoices = $invoices ?: [];
            // expose debug details to the view for troubleshooting
            $this->view->invoice_debug = $fetchDebug ?? [];
            $this->view->title = "Invoices";
            $this->view->filter_partner = $partnerId ?: null;
        } catch (\Throwable $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->invoices = [];
            $this->view->title = "Invoices";
            $this->view->invoice_debug = [['step' => 'global', 'success' => false, 'error' => $e->getMessage()]];
        }
    }
    
    /**
     * Create invoice
     */
    public function createAction()
    {
        if (!$this->odoo) {
            $this->flash->error("Odoo connection not available.");
            return $this->response->redirect('odoo-invoicing');
        }

        if ($this->request->isPost()) {
            try {
                $partnerId = (int)$this->request->getPost('partner_id');
                $productId = $this->request->getPost('product_id');
                $quantity = (float)$this->request->getPost('quantity');
                $price = (float)$this->request->getPost('price');
                $productName = $this->request->getPost('product_name') ?: 'Product';
                
                // Fetch product name if not provided
                if ($productId && $productName === 'Product') {
                    $prodData = $this->odoo->executePublic('product.product', 'read', [[(int)$productId], ['name']]);
                    if (!empty($prodData)) $productName = $prodData[0]['name'];
                }
                
                // Create Invoice with Atomic Line (Odoo 13+ standard)
                $invoiceData = [
                    'partner_id' => $partnerId,
                    'move_type' => 'out_invoice',
                    'invoice_date' => date('Y-m-d'),
                    'invoice_line_ids' => [
                        [0, 0, [
                            'product_id' => $productId ? (int)$productId : null,
                            'quantity' => $quantity,
                            'price_unit' => $price,
                            'name' => $productName
                        ]]
                    ]
                ];
                
                $invoiceId = $this->odoo->executePublic('account.move', 'create', [$invoiceData]);
                
                if (is_array($invoiceId)) $invoiceId = $invoiceId[0];
                
                // Optionally post the invoice immediately
                $posted = false;
                $postNow = (bool)$this->request->getPost('post_now');
                if ($postNow && $invoiceId) {
                    try {
                        $this->odoo->executePublic('account.move', 'action_post', [[$invoiceId]]);
                        $posted = true;
                    } catch (\Throwable $e) {
                        // posting failed, but invoice exists in draft
                        $this->flash->warning("Invoice dibuat (ID: $invoiceId) tetapi gagal dipost: " . $e->getMessage());
                        return $this->response->redirect('odoo-invoicing');
                    }
                }
                
                if ($invoiceId) {
                    $msg = "Invoice berhasil dibuat dengan ID: $invoiceId" . ($posted ? ' (posted)' : ' (draft)');
                    $this->flash->success($msg);
                    return $this->response->redirect('odoo-invoicing');
                }
            } catch (\Throwable $e) {
                $this->flash->error("Error: " . $e->getMessage());
            }
        }
        
        // Get customers dari res.partner
        try {
            $customers = $this->odoo->executePublic('res.partner', 'search_read',
                [[['customer_rank', '>', 0]]], // args: domain untuk filter customers
                ['fields' => ['name', 'email', 'phone']] // kwargs
            );
            $this->view->customers = $customers ?: [];
        } catch (\Throwable $e) {
            $this->view->customers = [];
        }
        
        // Get products dari product.product
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[]], // args: domain kosong untuk semua products
                ['fields' => ['name', 'default_code', 'list_price', 'type']] // kwargs
            );
            $this->view->products = $products ?: [];
        } catch (\Throwable $e) {
            $this->view->products = [];
        }
        
        $this->view->title = "Buat Invoice";
    }
    
    /**
     * View invoice
     */
    public function viewAction($id = null)
    {
        if (!$id) {
            $id = $this->dispatcher->getParam('id');
        }

        if (!$this->odoo) {
            $this->flash->error("Odoo connection not available.");
            return $this->response->redirect('odoo-invoicing');
        }
        
        try {
            // 1. Fetch Invoice Header and Line IDs
            $invoice = $this->odoo->executePublic('account.move', 'read', [
                [(int)$id],
                ['name', 'partner_id', 'invoice_date', 'invoice_date_due', 'amount_total', 'state', 'invoice_line_ids', 'move_type']
            ]);
            
            if (!empty($invoice)) {
                $invData = $invoice[0];
                
                // 2. Fetch Invoice Lines Details
                $lineIds = $invData['invoice_line_ids'];
                $lines = [];
                if (!empty($lineIds)) {
                    $lines = $this->odoo->executePublic('account.move.line', 'read', [
                        $lineIds,
                        ['product_id', 'name', 'quantity', 'price_unit', 'price_subtotal']
                    ]);
                }
                
                $invData['lines'] = $lines;
                
                $this->view->invoice = $invData;
                $this->view->title = "Invoice " . $invData['name'];
            } else {
                $this->flash->error("Invoice not found");
                return $this->response->redirect('odoo-invoicing');
            }
        } catch (\Throwable $e) {
            $this->flash->error("Error loading invoice: " . $e->getMessage());
            return $this->response->redirect('odoo-invoicing');
        }
    }
}
