-- Safe Migration untuk Inventory Table
-- Hanya menambah kolom, tidak rename/delete

-- Tambah kolom baru (jika belum ada)
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS odoo_id INT UNIQUE DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sku VARCHAR(100) DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS product_type VARCHAR(50) DEFAULT 'consu';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10, 2) DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'active';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS synced_to_odoo BOOLEAN DEFAULT FALSE;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sync_notes TEXT DEFAULT NULL;

-- Tambah indexes
CREATE INDEX IF NOT EXISTS idx_odoo_id ON inventory(odoo_id);
CREATE INDEX IF NOT EXISTS idx_sku ON inventory(sku);
CREATE INDEX IF NOT EXISTS idx_synced ON inventory(synced_to_odoo);

-- Update existing rows dengan default values
UPDATE inventory SET 
    product_type = COALESCE(product_type, 'consu'),
    status = COALESCE(status, 'active'),
    synced_to_odoo = COALESCE(synced_to_odoo, FALSE),
    cost_price = COALESCE(cost_price, 0)
WHERE product_type IS NULL OR product_type = '';

-- Selesai
SELECT "✓ Migration completed successfully" AS status;
