-- Initial Database Schema for Phalcon + Odoo Integration
-- Created on: 2026-02-03

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. Inventory Table
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `quantity` decimal(10,2) DEFAULT '0.00',
  `category` varchar(100) DEFAULT 'Uncategorized',
  `selling_price` decimal(10,2) DEFAULT '0.00',
  `cost_price` decimal(10,2) DEFAULT '0.00',
  `sku` varchar(255) DEFAULT NULL,
  `product_type` varchar(50) DEFAULT 'product',
  `status` varchar(50) DEFAULT 'active',
  `odoo_id` int(11) DEFAULT NULL,
  `synced_to_odoo` tinyint(1) DEFAULT '0',
  `last_sync_at` datetime DEFAULT NULL,
  `sync_notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_odoo_id` (`odoo_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL UNIQUE,
  `description` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `categories` (`name`, `description`) VALUES
('Electronics', 'Electronic devices and gadgets'),
('Accessories', 'Computer and device accessories'),
('Office', 'Office supplies and equipment'),
('Other', 'Other products');

-- 3. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `odoo_id` int(11) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_customer` tinyint(1) DEFAULT '0',
  `is_supplier` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Rental Logs Table
CREATE TABLE IF NOT EXISTS `rental_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `odoo_rental_id` int(11) DEFAULT NULL,
  `rental_number` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `equipment_name` varchar(255) DEFAULT NULL,
  `equipment_code` varchar(50) DEFAULT NULL,
  `rental_date` date DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT '0.00',
  `status` varchar(50) DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_odoo_rental` (`odoo_rental_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
