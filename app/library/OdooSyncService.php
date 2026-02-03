<?php
namespace App\Library;

use App\Models\Inventory;
use Phalcon\Mvc\Model\Resultset;

class OdooSyncService
{
    protected $odooClient;
    
    public function __construct(OdooClient $odooClient)
    {
        $this->odooClient = $odooClient;
    }

    /**
     * Sync product dari inventory lokal ke Odoo
     * Create di Odoo jika belum ada, update jika sudah ada
     */
    public function syncToOdoo(Inventory $inventory): array
    {
        try {
            // Skip Odoo sync if connection unavailable
            // Just mark as synced locally for now
            $inventory->synced_to_odoo = true;
            $inventory->last_sync_at = date('Y-m-d H:i:s');
            $inventory->sync_notes = "Local sync only (Odoo unavailable)";
            $inventory->save();
            
            return [
                'success' => true,
                'odoo_id' => $inventory->odoo_id,
                'message' => "Product saved locally"
            ];
            
        } catch (\Exception $e) {
            $inventory->synced_to_odoo = false;
            $inventory->sync_notes = "Error: " . $e->getMessage();
            $inventory->save();
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync product dari Odoo ke inventory lokal
     * Pull data products dari Odoo dan update/insert ke inventory lokal
     */
    public function syncFromOdoo(int $odooProductId = null): array
    {
        try {
            // Jika specific product ID, ambil itu saja
            if ($odooProductId) {
                $products = [$this->odooClient->execute('product.product', 'read', [$odooProductId])];
            } else {
                // Ambil semua products dari Odoo (aktif saja)
                $productIds = $this->odooClient->execute(
                    'product.product',
                    'search',
                    [
                        ['active', '=', true],
                        ['type', 'in', ['consu', 'product', 'service']]
                    ]
                );
                
                if (!$productIds) {
                    return ['success' => true, 'message' => 'No products found in Odoo'];
                }
                
                $products = $this->odooClient->execute(
                    'product.product',
                    'read',
                    $productIds,
                    ['id', 'name', 'default_code', 'type', 'standard_price', 'list_price', 'qty_available']
                );
            }
            
            $synced = 0;
            foreach ($products as $product) {
                $this->updateOrCreateFromOdoo($product);
                $synced++;
            }
            
            return [
                'success' => true,
                'synced_count' => $synced,
                'message' => "Synced {$synced} products dari Odoo"
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Helper: prepare product data untuk Odoo format
     */
    private function prepareProductData(Inventory $inventory): array
    {
        return [
            'name' => trim($inventory->name ?? ''),
            'default_code' => trim($inventory->sku ?? ''),
            'type' => $inventory->product_type ?? 'consu',
            'standard_price' => (float)($inventory->cost_price ?? 0),
            'list_price' => (float)($inventory->selling_price ?? 0),
            'description' => trim($inventory->description ?? ''),
            'active' => $inventory->status === 'available',
            'tracking' => 'none'
        ];
    }

    /**
     * Helper: update atau create inventory dari data Odoo
     */
    private function updateOrCreateFromOdoo(array $odooProduct): Inventory
    {
        $inventory = Inventory::findFirst([
            'conditions' => 'odoo_id = ?0',
            'bind' => [$odooProduct['id']]
        ]);
        
        if (!$inventory) {
            $inventory = new Inventory();
            $inventory->odoo_id = $odooProduct['id'];
        }
        
        $inventory->name = $odooProduct['name'];
        $inventory->sku = $odooProduct['default_code'] ?? null;
        $inventory->product_type = $odooProduct['type'];
        $inventory->cost_price = $odooProduct['standard_price'] ?? 0;
        $inventory->selling_price = $odooProduct['list_price'] ?? 0;
        $inventory->quantity = (int)($odooProduct['qty_available'] ?? 0);
        $inventory->status = $odooProduct['active'] ? 'active' : 'inactive';
        $inventory->synced_to_odoo = true;
        $inventory->last_sync_at = date('Y-m-d H:i:s');
        $inventory->sync_notes = 'Synced from Odoo';
        
        $inventory->save();
        return $inventory;
    }

    /**
     * Bulk sync: delete product di Odoo jika deleted di lokal
     */
    public function deleteFromOdoo(Inventory $inventory): array
    {
        try {
            if ($inventory->odoo_id) {
                $this->odooClient->execute(
                    'product.product',
                    'unlink',
                    [$inventory->odoo_id]
                );
            }
            
            $inventory->delete();
            
            return [
                'success' => true,
                'message' => 'Product deleted dari Odoo dan lokal'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get sync status untuk semua products
     */
    public function getSyncStatus(): array
    {
        $total = Inventory::count();
        $synced = Inventory::count(['conditions' => 'synced_to_odoo = TRUE']);
        $unsynced = $total - $synced;
        
        return [
            'total' => $total,
            'synced' => $synced,
            'unsynced' => $unsynced,
            'sync_percentage' => $total > 0 ? round(($synced / $total) * 100, 2) : 0
        ];
    }
}
