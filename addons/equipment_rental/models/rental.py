from odoo import models, fields, api
from datetime import timedelta

class RentalTransaction(models.Model):
    _name = 'rental.transaction'
    _description = 'Equipment Rental Transaction'
    _order = 'rental_date desc'

    name = fields.Char(string='Rental No', required=True, readonly=True, default='New')
    customer_name = fields.Char(string='Customer Name', required=True)
    customer_phone = fields.Char(string='Phone Number')
    customer_email = fields.Char(string='Email')
    
    equipment_id = fields.Many2one('equipment.rental', string='Equipment', required=True)
    rental_date = fields.Date(string='Rental Date', required=True, default=fields.Date.today)
    return_date = fields.Date(string='Expected Return Date', required=True)
    actual_return_date = fields.Date(string='Actual Return Date')
    
    duration_days = fields.Integer(string='Duration (Days)', compute='_compute_duration', store=True)
    daily_rate = fields.Float(string='Daily Rate', related='equipment_id.daily_rate', store=True)
    total_amount = fields.Float(string='Total Amount', compute='_compute_total_amount', store=True)
    
    deposit = fields.Float(string='Deposit Amount')
    payment_status = fields.Selection([
        ('pending', 'Pending'),
        ('paid', 'Paid'),
        ('partial', 'Partial'),
        ('refunded', 'Refunded')
    ], string='Payment Status', default='pending')
    
    state = fields.Selection([
        ('draft', 'Draft'),
        ('confirmed', 'Confirmed'),
        ('ongoing', 'Ongoing'),
        ('returned', 'Returned'),
        ('cancelled', 'Cancelled')
    ], string='Status', default='draft')
    
    notes = fields.Text(string='Notes')
    
    @api.depends('rental_date', 'return_date')
    def _compute_duration(self):
        for record in self:
            if record.rental_date and record.return_date:
                delta = record.return_date - record.rental_date
                record.duration_days = delta.days + 1
            else:
                record.duration_days = 0
    
    @api.depends('duration_days', 'daily_rate')
    def _compute_total_amount(self):
        for record in self:
            record.total_amount = record.duration_days * record.daily_rate
    
    @api.model
    def create(self, vals):
        if vals.get('name', 'New') == 'New':
            vals['name'] = self.env['ir.sequence'].next_by_code('rental.transaction') or 'New'
        return super(RentalTransaction, self).create(vals)
    
    def action_confirm(self):
        self.state = 'confirmed'
        self.equipment_id.status = 'rented'
    
    def action_start_rental(self):
        self.state = 'ongoing'
    
    def action_return(self):
        self.state = 'returned'
        self.actual_return_date = fields.Date.today()
        self.equipment_id.status = 'available'
    
    def action_cancel(self):
        self.state = 'cancelled'
        self.equipment_id.status = 'available'
