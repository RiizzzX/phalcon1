{
    'name': 'Equipment Rental',
    'version': '1.0',
    'category': 'Inventory',
    'summary': 'Manage equipment rental and tracking',
    'description': """
        Equipment Rental Management
        ============================
        - Track equipment inventory
        - Manage rental transactions
        - Customer rental history
        - Availability status
    """,
    'author': 'Your Company',
    'depends': ['base'],
    'data': [
        'security/ir.model.access.csv',
        'views/equipment_views.xml',
        'views/rental_views.xml',
    ],
    'installable': True,
    'application': True,
    'auto_install': False,
}
