# Inventory API Documentation

The Inventory API allows other applications to integrate with the inventory system. All stock tracking is **optional** - you can use inventory freely without managing stock.

## Base URL
```
http://localhost/inventory-api
```

## Endpoints

### 1. List Products
**GET** `/inventory-api/list`

List all products with optional filters.

**Query Parameters:**
- `category` (optional): Filter by category
- `status` (optional): Filter by status (e.g., "active")
- `limit` (optional): Max results (default: 50)

**Example Request:**
```bash
curl http://localhost/inventory-api/list?category=Electronics&limit=20
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Laptop Dell XPS",
      "category": "Electronics",
      "sku": "SKU-001",
      "quantity": 10,
      "cost_price": 12000000,
      "selling_price": 15000000,
      "product_type": "product",
      "status": "active",
      "description": "...",
      "created_at": "2026-01-29 10:30:00",
      "updated_at": "2026-01-29 10:30:00"
    }
  ],
  "count": 1
}
```

---

### 2. Get Single Product
**GET** `/inventory-api/view/{id}`

Get details of a specific product.

**Example Request:**
```bash
curl http://localhost/inventory-api/view/1
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Laptop Dell XPS",
    "category": "Electronics",
    ...
  }
}
```

---

### 3. Create Product
**POST** `/inventory-api/create`

Create a new product. Only `name` and `category` are required.

**Request Body (JSON):**
```json
{
  "name": "iPhone 15 Pro",
  "category": "Electronics",
  "sku": "SKU-002",
  "description": "Latest Apple smartphone",
  "quantity": 5,
  "cost_price": 8000000,
  "selling_price": 10000000,
  "product_type": "product",
  "status": "active"
}
```

**Example Request:**
```bash
curl -X POST http://localhost/inventory-api/create \
  -H "Content-Type: application/json" \
  -d '{
    "name": "iPhone 15 Pro",
    "category": "Electronics"
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Product created",
  "data": {
    "id": 2,
    "name": "iPhone 15 Pro",
    "category": "Electronics",
    ...
  }
}
```

---

### 4. Update Product
**PUT** `/inventory-api/update/{id}`

Update an existing product. Send only the fields you want to update.

**Request Body (JSON):**
```json
{
  "name": "iPhone 15 Pro Max",
  "quantity": 10,
  "selling_price": 12000000
}
```

**Example Request:**
```bash
curl -X PUT http://localhost/inventory-api/update/1 \
  -H "Content-Type: application/json" \
  -d '{
    "quantity": 10,
    "status": "inactive"
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Product updated",
  "data": { ... }
}
```

---

### 5. Delete Product
**DELETE** `/inventory-api/delete/{id}`

Delete a product.

**Example Request:**
```bash
curl -X DELETE http://localhost/inventory-api/delete/1
```

**Response:**
```json
{
  "success": true,
  "message": "Product deleted"
}
```

---

### 6. Adjust Stock
**POST** `/inventory-api/adjust-stock/{id}`

Adjust product quantity (add or subtract).

**Request Body (JSON):**
```json
{
  "quantity_change": 5,
  "note": "Received from supplier"
}
```

Positive number = add stock | Negative number = reduce stock

**Example Request:**
```bash
# Add 10 units
curl -X POST http://localhost/inventory-api/adjust-stock/1 \
  -H "Content-Type: application/json" \
  -d '{ "quantity_change": 10 }'

# Subtract 3 units
curl -X POST http://localhost/inventory-api/adjust-stock/1 \
  -H "Content-Type: application/json" \
  -d '{ "quantity_change": -3 }'
```

**Response:**
```json
{
  "success": true,
  "message": "Stock adjusted",
  "old_quantity": 5,
  "new_quantity": 15,
  "change": 10,
  "data": { ... }
}
```

---

### 7. Search Products
**POST** `/inventory-api/search`

Search products by name, SKU, or description.

**Request Body (JSON):**
```json
{
  "query": "laptop"
}
```

**Example Request:**
```bash
curl -X POST http://localhost/inventory-api/search \
  -H "Content-Type: application/json" \
  -d '{ "query": "dell" }'
```

**Response:**
```json
{
  "success": true,
  "data": [ ... ],
  "count": 3
}
```

---

## Error Responses

**400 Bad Request:**
```json
{
  "success": false,
  "error": "Missing required fields: name, category"
}
```

**404 Not Found:**
```json
{
  "success": false,
  "error": "Product not found"
}
```

**500 Server Error:**
```json
{
  "success": false,
  "error": "Database connection error"
}
```

---

## Integration Examples

### JavaScript/Node.js
```javascript
// Create product
const response = await fetch('http://localhost/inventory-api/create', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    name: 'New Product',
    category: 'Electronics'
  })
});
const result = await response.json();
console.log(result.data.id); // Get new product ID
```

### Python
```python
import requests

# List products
response = requests.get('http://localhost/inventory-api/list', 
  params={'category': 'Electronics'})
products = response.json()['data']

# Adjust stock
requests.post(
  'http://localhost/inventory-api/adjust-stock/1',
  json={'quantity_change': 5}
)
```

### PHP
```php
// Create product
$data = [
  'name' => 'New Product',
  'category' => 'Office'
];

$response = file_get_contents(
  'http://localhost/inventory-api/create',
  false,
  stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => 'Content-Type: application/json',
      'content' => json_encode($data)
    ]
  ])
);

$result = json_decode($response, true);
if ($result['success']) {
  echo "Created product ID: " . $result['data']['id'];
}
```

---

## Notes

- **Stock is completely optional** - products don't require quantity tracking
- **No authentication required** - API is open (add security if needed)
- **All prices are in Rupiah (Rp)** unless specified otherwise
- **Product Type** options: `consu` (Consumable), `product` (Storable), `service` (Service)
- **Status** options: `active`, `inactive` (create your own as needed)
