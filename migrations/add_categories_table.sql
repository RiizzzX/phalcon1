-- Migration: Add categories table for product categories dropdown
-- Run this to add the categories table and populate initial categories

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default categories
INSERT IGNORE INTO categories (name, description) VALUES
('Electronics', 'Electronic devices and gadgets'),
('Accessories', 'Computer and device accessories'),
('Office', 'Office supplies and equipment'),
('Other', 'Other products');
