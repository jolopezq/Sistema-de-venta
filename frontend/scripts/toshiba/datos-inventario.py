"""SOLO SOBRE UNA COPIA DE LA BASE (nunca producción).
Carga un inventario completo de prueba en la COPIA de la base (nube), por la API real."""
import json, os, sys, urllib.request

BASE = os.environ.get('API_URL', 'http://127.0.0.1:8000/api')
# Sesión propia con AUDIT_EMAIL / AUDIT_PASSWORD (el token queda solo en memoria)
_login = urllib.request.Request(BASE + '/login', method='POST', data=json.dumps({'email': os.environ['AUDIT_EMAIL'], 'password': os.environ['AUDIT_PASSWORD']}).encode(),
                                headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
TOK = json.loads(urllib.request.urlopen(_login, timeout=120).read())['access_token']


def call(method, path, data=None):
    req = urllib.request.Request(BASE + path, method=method, data=json.dumps(data).encode() if data is not None else None,
                                 headers={'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': f'Bearer {TOK}'})
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        print('ERROR', method, path, e.code, e.read()[:300])
        raise


def unwrap(x):
    return x.get('data', x) if isinstance(x, dict) else x


cats = {}
for name in ['Frutas', 'Lácteos y cremas', 'Toppings', 'Bases y helados', 'Descartables']:
    cats[name] = unwrap(call('POST', '/ingredient-categories', {'name': name}))['id']
sups = {s['name']: s['id'] for s in unwrap(call('GET', '/suppliers'))}
for name, days in [('Frutería Doña Rosa', 0), ('Plásticos del Oriente', 2), ('Distribuidora Lácteos Santa Cruz', 1)]:
    sups[name] = unwrap(call('POST', '/suppliers', {'name': name, 'delivery_days': days}))['id']

# nombre, unidad, stock, mínimo, costo, tipo, categoría, proveedor, política, días objetivo, consumo diario, clave
ROWS = [
    ('Frutilla', 'kg', 2.5, 3, 18, 'perecedero', 'Frutas', 'Frutería Doña Rosa', 'daily', None, 2.2, True),
    ('Kiwi', 'kg', 0.8, 1, 28, 'perecedero', 'Frutas', 'Frutería Doña Rosa', 'daily', None, 0.6, False),
    ('Mango', 'kg', 4, 2, 10, 'perecedero', 'Frutas', 'Mercado Central (Frutas)', 'daily', None, 1.1, False),
    ('Piña', 'unidades', 3, 2, 12, 'perecedero', 'Frutas', 'Mercado Central (Frutas)', 'daily', None, 1, False),
    ('Arándanos congelados', 'kg', 0, 1, 70, 'perecedero', 'Frutas', 'Distribuidora Açaí', 'reorder', 14, 0.15, False),
    ('Leche en polvo', 'kg', 1.2, 2, 48, 'no_perecedero', 'Lácteos y cremas', 'Distribuidora Lácteos Santa Cruz', 'reorder', 10, 0.35, True),
    ('Leche condensada', 'unidades', 6, 10, 12, 'no_perecedero', 'Lácteos y cremas', 'Distribuidora Lácteos Santa Cruz', 'reorder', 10, 2.5, True),
    ('Miel de abeja', 'litros', 0.9, 1, 45, 'no_perecedero', 'Toppings', 'Granola Artesanal', 'reorder', 21, 0.08, False),
    ('Crema de avellanas', 'kg', 0.4, 1, 95, 'no_perecedero', 'Toppings', 'Distribuidora Açaí', 'reorder', 14, 0.12, False),
    ('Coco rallado', 'kg', 1, 0.5, 30, 'no_perecedero', 'Toppings', 'Granola Artesanal', 'reorder', 21, 0.05, False),
    ('Chips de chocolate', 'kg', 0.3, 0.5, 60, 'no_perecedero', 'Toppings', 'Granola Artesanal', 'reorder', 14, 0.06, False),
    ('Paçoca', 'unidades', 40, 20, 1.5, 'no_perecedero', 'Toppings', 'Distribuidora Açaí', 'reorder', 14, 3, False),
    ('Helado de vainilla', 'litros', 6, 4, 22, 'perecedero', 'Bases y helados', 'Distribuidora Lácteos Santa Cruz', 'reorder', 7, 0.9, True),
    ('Pulpa de açaí orgánica sin azúcar (paquete de 1 kg, importada)', 'kg', 3, 5, 38, 'perecedero', 'Bases y helados', 'Distribuidora Açaí', 'reorder', 7, 0.5, False),
    ('Vaso 300 ml', 'unidades', 180, 200, 0.45, 'material_empaque', 'Descartables', 'Plásticos del Oriente', 'reorder', 14, 35, True),
    ('Vaso 500 ml', 'unidades', 320, 200, 0.6, 'material_empaque', 'Descartables', 'Plásticos del Oriente', 'reorder', 14, 25, True),
    ('Tapa domo', 'unidades', 90, 150, 0.25, 'material_empaque', 'Descartables', 'Plásticos del Oriente', 'reorder', 14, 40, False),
    ('Cucharilla', 'unidades', 600, 300, 0.08, 'material_empaque', 'Descartables', 'Plásticos del Oriente', 'reorder', 21, 60, False),
    ('Bolsa delivery', 'unidades', 50, 100, 0.3, 'material_empaque', 'Descartables', 'Plásticos del Oriente', 'reorder', 14, 12, False),
    ('Servilletas', 'unidades', 12, 10, 4, 'otros', 'Descartables', 'Plásticos del Oriente', 'none', None, None, False),
]
for (n, u, st, mn, cost, typ, cat, sup, pol, td, de, key) in ROWS:
    body = {'name': n, 'unit': u, 'current_stock': st, 'minimum_stock': mn, 'unit_cost': cost, 'weighted_avg_cost': cost, 'type': typ,
            'ingredient_category_id': cats[cat], 'supplier_id': sups[sup], 'reorder_policy': pol, 'is_key': key}
    if td: body['target_days'] = td
    if de is not None: body['daily_estimate'] = de
    call('POST', '/ingredients', body)
# Las tres existentes pasan a categorías
ing = {i['name']: i for i in unwrap(call('GET', '/ingredients'))}
for n, cat in [('Pulpa de Açaí', 'Bases y helados'), ('Granola', 'Toppings'), ('Banana', 'Frutas')]:
    i = ing[n]
    body = {k: i[k] for k in ['name', 'unit', 'current_stock', 'minimum_stock', 'unit_cost', 'type', 'supplier_id', 'reorder_policy', 'is_key', 'target_days', 'safety_days', 'shelf_life_days', 'daily_estimate', 'purchase_unit', 'purchase_factor', 'lot_size'] if i.get(k) is not None}
    body['ingredient_category_id'] = cats[cat]
    call('PUT', f"/ingredients/{i['id']}", body)
print('insumos:', len(unwrap(call('GET', '/ingredients'))), 'categorías:', len(unwrap(call('GET', '/ingredient-categories'))), 'proveedores:', len(unwrap(call('GET', '/suppliers'))))
