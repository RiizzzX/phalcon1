<?php

use App\Library\OdooClient;

class OdooInvoicingController extends OdooControllerBase
{
    /**
     * List invoices
     */
    public function indexAction()
    {
        try {
            $invoices = $this->odoo->executePublic('account.move', 'search_read',
                [[['move_type', '=', 'out_invoice']]], // args: domain
                ['fields' => ['name', 'partner_id', 'invoice_date', 'amount_total', 'state']] // kwargs
            );
            
            $this->view->invoices = $invoices ?: [];
            $this->view->title = "Invoices";
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            $this->view->invoices = [];
        }
    }
    
    /**
     * Create invoice
     */
    public function createAction()
    {
        if ($this->request->isPost()) {
            try {
                $partnerId = (int)$this->request->getPost('partner_id');
                $productId = $this->request->getPost('product_id');
                $quantity = (float)$this->request->getPost('quantity');
                $price = (float)$this->request->getPost('price');
                
                // Create Invoice header
                $invoiceData = [
                    'partner_id' => $partnerId,
                    'move_type' => 'out_invoice',
                    'invoice_date' => date('Y-m-d')
                ];
                
                $invoiceId = $this->odoo->executePublic('account.move', 'create', [[$invoiceData]]);
                
                if ($invoiceId && $productId) {
                    // Create Invoice Line
                    $lineData = [
                        'move_id' => $invoiceId,
                        'product_id' => (int)$productId,
                        'quantity' => $quantity,
                        'price_unit' => $price,
                        'name' => $this->request->getPost('product_name') ?: 'Product'
                    ];
                    
                    $this->odoo->executePublic('account.move.line', 'create', [[$lineData]]);
                }
                
                if ($invoiceId) {
                    $this->flash->success("Invoice berhasil dibuat dengan ID: $invoiceId");
                    return $this->response->redirect('odoo-invoicing');
                }
            } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            $this->view->customers = [];
        }
        
        // Get products dari product.product
        try {
            $products = $this->odoo->executePublic('product.product', 'search_read',
                [[]], // args: domain kosong untuk semua products
                ['fields' => ['name', 'default_code', 'list_price', 'type']] // kwargs
            );
            $this->view->products = $products ?: [];
        } catch (\Exception $e) {
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
        
        try {
            $invoice = $this->odoo->executePublic('account.move', 'read', [
                [$id],
                ['name', 'partner_id', 'invoice_date', 'amount_total', 'state']
            ]);
            
            if (!empty($invoice)) {
                $this->view->invoice = $invoice[0];
                $this->view->title = "Invoice Detail";
            } else {
                $this->flash->error("Invoice not found");
                return $this->response->redirect('odoo-invoicing');
            }
        } catch (\Exception $e) {
            $this->flash->error("Error: " . $e->getMessage());
            return $this->response->redirect('odoo-invoicing');
        }
    }
}
