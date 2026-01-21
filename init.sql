CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL
);

INSERT INTO users (name, email) VALUES
('Budi', 'budi@example.com'),
('Ani', 'ani@example.com');

CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    quantity INT NOT NULL DEFAULT 0,
    category VARCHAR(100),
    price DECIMAL(10, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO inventory (name, description, quantity, category, price) VALUES
('Laptop', 'Dell XPS 13', 5, 'Electronics', 1500.00),
('Monitor', 'LG 27 inch', 10, 'Electronics', 300.00),
('Keyboard', 'Mechanical RGB', 20, 'Accessories', 150.00);

-- Table untuk log rental dari Odoo
CREATE TABLE IF NOT EXISTS rental_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    odoo_rental_id INT NOT NULL,
    rental_number VARCHAR(50),
    customer_name VARCHAR(255) NOT NULL,
    customer_phone VARCHAR(50),
    equipment_name VARCHAR(255),
    equipment_code VARCHAR(50),
    rental_date DATE,
    return_date DATE,
    total_amount DECIMAL(15, 2),
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_odoo_rental (odoo_rental_id),
    INDEX idx_customer (customer_name),
    INDEX idx_status (status)
);