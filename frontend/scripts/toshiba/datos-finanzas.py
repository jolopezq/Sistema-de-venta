"""SOLO SOBRE UNA COPIA DE LA BASE (nunca producción).
Gastos y gastos fijos de prueba en la COPIA de la base (nube), por la API real (octubre 2026)."""
import json, os, sys, urllib.request

BASE = os.environ.get('API_URL', 'http://127.0.0.1:8000/api')
# Sesión propia con AUDIT_EMAIL / AUDIT_PASSWORD (el token queda solo en memoria)
_login = urllib.request.Request(BASE + '/login', method='POST', data=json.dumps({'email': os.environ['AUDIT_EMAIL'], 'password': os.environ['AUDIT_PASSWORD']}).encode(),
                                headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
TOK = json.loads(urllib.request.urlopen(_login, timeout=120).read())['access_token']


def call(method, path, data=None):
    req = urllib.request.Request(BASE + path, method=method, data=json.dumps(data).encode() if data is not None else None,
                                 headers={'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': f'Bearer {TOK}'})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.loads(r.read() or b'{}')


_c = call('GET', '/expense-categories')
cats = {c['name']: c['id'] for c in (_c.get('data', _c) if isinstance(_c, dict) else _c)}
gastos = [
    ('2026-10-02', 'Helado y açaí', 'Distribuidora Açaí', 1240, 'bank_transfer', 'F-1021'),
    ('2026-10-03', 'Descartables (vasos, cucharas)', 'Plásticos del Oriente', 386.5, 'qr', None),
    ('2026-10-05', 'Alquiler local', 'Don Ernesto (alquiler)', 2500, 'bank_transfer', None),
    ('2026-10-07', 'Servicios básicos (luz, agua)', 'CRE (luz)', 342.8, 'qr', '000981'),
    ('2026-10-08', 'Internet y teléfono', 'Tigo Hogar', 199, 'card', None),
    ('2026-10-10', 'Publicidad propia y marketing', 'Meta Ads', 150, 'card', None),
    ('2026-10-12', 'Mantenimiento y reparaciones', 'Técnico de congeladoras', 280, 'cash', None),
    ('2026-10-14', 'Limpieza e insumos operativos', 'Supermercado', 96.4, 'cash', None),
    ('2026-10-16', 'Empaques delivery', 'Plásticos del Oriente', 210, 'qr', None),
    ('2026-10-18', 'Ingredientes y frutas', 'Frutería Doña Rosa', 118, 'cash', None),
    ('2026-10-20', 'Intereses y comisiones bancarias', 'Banco (comisión QR)', 32.6, 'bank_transfer', None),
    ('2026-10-21', 'Otros gastos operativos', 'Gas licuado (garrafa)', 22.5, 'cash', None),
]
for d, cat, sup, amt, pm, rec in gastos:
    call('POST', '/finance/expenses', {'date': d, 'expense_category_id': cats[cat], 'supplier': sup, 'amount': amt, 'payment_method': pm, 'receipt_number': rec})
fijos = [
    ('Alquiler del local', 'Alquiler local', 2500, 'monthly', 5),
    ('Sueldo cajera', 'Salarios y personal', 2800, 'monthly', 30),
    ('Luz (CRE)', 'Servicios básicos (luz, agua)', 350, 'monthly', 10),
    ('Agua (Saguapac)', 'Servicios básicos (luz, agua)', 90, 'bimonthly', 12),
    ('Internet Tigo', 'Internet y teléfono', 199, 'monthly', 8),
    ('Régimen simplificado', 'Régimen simplificado', 150, 'bimonthly', 15),
    ('Mantenimiento de congeladoras', 'Mantenimiento y reparaciones', 280, 'quarterly', 12),
    ('Publicidad en redes', 'Publicidad propia y marketing', 150, 'monthly', 10),
]
for name, cat, amt, freq, day in fijos:
    call('POST', '/finance/recurring-expenses', {'name': name, 'expense_category_id': cats[cat], 'amount': amt, 'frequency': freq, 'day_of_month': day, 'start_month': '2026-08', 'payment_method': 'bank_transfer', 'is_active': True})
print('gastos', len(gastos), 'fijos', len(fijos))
