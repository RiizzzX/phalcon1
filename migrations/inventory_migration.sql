-- Safe migration for inventory table expansion
-- Only adds new columns if they don't exist, no destructive operations

ALTER TABLE inventory ADD COLUMN IF NOT EXISTS odoo_id INT DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sku VARCHAR(255) DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS product_type VARCHAR(50) DEFAULT 'consu';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10,2) DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'available';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS synced_to_odoo TINYINT DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS last_sync_at DATETIME DEFAULT NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sync_notes TEXT DEFAULT NULL;

-- Create indexes for better query performance
CREATE INDEX IF NOT EXISTS idx_odoo_id ON inventory(odoo_id);
CREATE INDEX IF NOT EXISTS idx_sku ON inventory(sku);
CREATE INDEX IF NOT EXISTS idx_synced ON inventory(synced_to_odoo);
