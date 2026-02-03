-- Migration: Expand inventory table untuk sync dengan Odoo
-- Date: 2026-01-28

-- Add new columns (safe - IF NOT EXISTS)
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS odoo_id INT UNIQUE;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sku VARCHAR(100) UNIQUE;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS product_type VARCHAR(50) DEFAULT 'consu';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS cost_price DECIMAL(10, 2) DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'active';
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS synced_to_odoo BOOLEAN DEFAULT FALSE;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS last_sync_at TIMESTAMP NULL;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS sync_notes TEXT;

-- Keep 'price' column as-is (backward compatible)
-- Just add alias for code: price = selling_price

-- Create index untuk performance
CREATE INDEX IF NOT EXISTS idx_odoo_id ON inventory(odoo_id);
CREATE INDEX IF NOT EXISTS idx_sku ON inventory(sku);
CREATE INDEX IF NOT EXISTS idx_synced ON inventory(synced_to_odoo);

-- Update existing data with defaults
UPDATE inventory SET 
    product_type = 'consu',
    status = 'active',
    synced_to_odoo = FALSE,
    cost_price = COALESCE(price, 0) * 0.6
WHERE product_type IS NULL OR product_type = '';

-- Done!
SELECT 'Migration completed successfully' AS status;
