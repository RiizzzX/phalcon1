from odoo import models, fields, api

class Equipment(models.Model):
    _name = 'equipment.rental'
    _description = 'Equipment for Rental'
    _rec_name = 'name'

    name = fields.Char(string='Equipment Name', required=True)
    code = fields.Char(string='Equipment Code', required=True)
    category = fields.Selection([
        ('camera', 'Camera'),
        ('lens', 'Lens'),
        ('lighting', 'Lighting'),
        ('audio', 'Audio Equipment'),
        ('other', 'Other')
    ], string='Category', required=True)
    
    description = fields.Text(string='Description')
    daily_rate = fields.Float(string='Daily Rental Rate', required=True)
    purchase_price = fields.Float(string='Purchase Price')
    status = fields.Selection([
        ('available', 'Available'),
        ('rented', 'Rented'),
        ('maintenance', 'Under Maintenance'),
        ('damaged', 'Damaged')
    ], string='Status', default='available', required=True)
    
    condition = fields.Selection([
        ('new', 'New'),
        ('good', 'Good'),
        ('fair', 'Fair'),
        ('poor', 'Poor')
    ], string='Condition', default='good')
    
    purchase_date = fields.Date(string='Purchase Date')
    image = fields.Binary(string='Image')
    
    # Relasi ke rental transactions
    rental_ids = fields.One2many('rental.transaction', 'equipment_id', string='Rental History')
    rental_count = fields.Integer(string='Total Rentals', compute='_compute_rental_count')
    
    @api.depends('rental_ids')
    def _compute_rental_count(self):
        for record in self:
            record.rental_count = len(record.rental_ids)
    
    def action_set_maintenance(self):
        self.status = 'maintenance'
    
    def action_set_available(self):
        self.status = 'available'
