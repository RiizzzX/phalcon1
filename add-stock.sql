-- Insert stock quants directly for testing
INSERT INTO stock_quant (product_id, location_id, quantity, reserved_quantity, company_id, create_uid, write_uid, create_date, write_date, in_date)
SELECT 
    pp.id,
    8, -- Stock location
    CASE 
        WHEN pp.id = 1 THEN 50
        WHEN pp.id = 2 THEN 100
        WHEN pp.id = 3 THEN 75
        WHEN pp.id = 4 THEN 30
        WHEN pp.id = 5 THEN 60
        ELSE 25
    END,
    0,
    1,
    2,
    2,
    NOW(),
    NOW(),
    NOW()
FROM product_product pp
WHERE pp.id IN (1, 2, 3, 4, 5)
ON CONFLICT DO NOTHING;

-- Verify
SELECT pp.id, pt.name, sq.quantity 
FROM product_product pp 
JOIN product_template pt ON pp.product_tmpl_id = pt.id 
LEFT JOIN stock_quant sq ON sq.product_id = pp.id AND sq.location_id = 8
LIMIT 10;
