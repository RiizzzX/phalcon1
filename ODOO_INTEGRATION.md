# Odoo Integration Setup

## 📋 Modules yang Diintegrasikan

1. **Purchase Management** (`/odoo-purchase`)
2. **Inventory Management** (`/odoo-inventory`)
3. **Sales Management** (`/odoo-sales`)
4. **Invoicing** (`/odoo-invoicing`)
5. **Equipment Rental** (`/odoo-equipment`)

## 🚀 Cara Setup

### 1. Install Modules di Odoo

**Otomatis** (Recommended):
```
Buka browser: http://localhost:8082/install-odoo-modules.html
Klik "Install All Modules Now"
```

**Manual via Odoo UI**:
```
1. Buka http://localhost:8069
2. Login dengan: farizlahya@gmail.com / guwosari6b
3. Aktifkan Developer Mode: Settings → Activate Developer Mode
4. Apps → Hapus filter "Apps" → Search:
   - Purchase
   - Inventory (stock)
   - Sales
   - Invoicing (account)
5. Install satu per satu
```

### 2. Akses Odoo Dashboard

```
http://localhost:8082/odoo-dashboard
```

Dari dashboard, klik modul yang ingin digunakan:
- 🛒 Purchase Management
- 📦 Inventory Management  
- 💰 Sales Management
- 🧾 Invoicing
- 🎬 Equipment Rental

### 3. Test Integrasi

**Purchase Orders**:
```
http://localhost:8082/odoo-purchase
- Create purchase order
- View purchase orders list
```

**Inventory/Products**:
```
http://localhost:8082/odoo-inventory
- View products
- View stock movements
- Create new products
```

**Sales Orders**:
```
http://localhost:8082/odoo-sales
- Create sales order
- View sales orders list
```

**Invoices**:
```
http://localhost:8082/odoo-invoicing
- Create invoices
- View invoices list
```

## 🔧 Teknologi

- **Frontend**: PHP Phalcon 5.0
- **Backend**: Odoo 17.0 (Python)
- **Communication**: XML-RPC Protocol
- **Database**: 
  - PostgreSQL 15 (Odoo data)
  - MySQL 8.0 (Phalcon + rental logs)

## 📊 Database Integration

Data disimpan di 2 database:
1. **PostgreSQL** - Master data Odoo (products, orders, invoices)
2. **MySQL** - Rental logs dan inventory Phalcon

Auto-sync terjadi saat:
- Rental transaction dibuat
- Rentals list diakses

## 🎯 Next Steps

1. Tambahkan data master (products, customers, suppliers) di Odoo
2. Test create purchase order dari Phalcon
3. Test create sales order dari Phalcon
4. Test create invoice dari Phalcon
5. Deploy to production server

## ⚙️ Configuration

Edit kredensial di `app/library/OdooClient.php`:
```php
$this->url = 'http://odoo:8069';
$this->db = 'coba_odoo';
$this->username = 'farizlahya@gmail.com';
$this->password = 'guwosari6b';
```

## 🆘 Troubleshooting

**Module tidak muncul?**
- Pastikan modules sudah installed di Odoo
- Update apps list di Odoo: Apps → Update Apps List

**Error koneksi?**
- Cek docker containers running: `docker ps`
- Restart containers: `docker-compose restart`

**Authentication failed?**
- Verify credentials di Odoo
- Database name harus sesuai dengan yang dibuat

## 📞 Support

Module ini menggunakan XML-RPC API Odoo.
Dokumentasi: https://www.odoo.com/documentation/17.0/developer/reference/external_api.html
