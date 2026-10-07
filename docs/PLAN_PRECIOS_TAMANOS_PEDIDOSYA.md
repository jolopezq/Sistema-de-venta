# Plan: precio PedidosYa solo en los tamaños

> Continuación de [PLAN_PRECIOS_PEDIDOSYA.md](PLAN_PRECIOS_PEDIDOSYA.md) y de
> [ANALISIS_UI_OPCIONALES.md](ANALISIS_UI_OPCIONALES.md). Antes de empezar, lee `AGENTS.md` y
> `docs/BITACORA.md` y sigue sus reglas (rama, plan aprobado, respaldo, tests, CHANGELOG y bitácora).

## 1. Objetivo

En PedidosYa, **los tamaños** (Tamaño Acai, Tamaño Cafe) pueden tener un precio distinto al del local.
**Los demás opcionales** (toppings, frutas) cobran **siempre el mismo precio** en los dos canales: no deben
pedir precio PedidosYa, ni avisar que les falta, ni poder tenerlo por error.

## 2. Diagnóstico

Hoy ya se puede poner precio PedidosYa a un tamaño, pero la regla trata igual a todos los opcionales:

| Dónde | Qué pasa hoy |
|---|---|
| Opcionales → editar opcional (`OptionGroupDetail.vue`) | El campo "Precio adicional en delivery (BOB)" aparece en **todos** los opcionales, también en toppings y frutas. |
| Lista de opcionales | Solo muestra el precio local (`+7,00 BOB`); el precio PedidosYa del tamaño no se ve. |
| Tarifario PedidosYa (rama `feat/precios-pedidosya`) | La sección "Tamaños y toppings" mezcla los dos tipos. |
| POS en modo PedidosYa (`Pos.vue`) | Avisa "Sin precio PedidosYa" por toppings que no lo necesitan (Ositos de gomita, Chips de Chocolate, Hojuelas de maíz, Chispas de chocolate). |
| Regla de precio (`channelPricing.js` y `SalePricingService::optionPrice`) | Cualquier opcional con `delivery_price > 0` usa ese precio en PedidosYa, sea tamaño o topping. |

Datos actuales (base local, 07/10/2026):

| Grupo | Opción | Local | PedidosYa |
|---|---|---|---|
| Tamaño Acai | Mediano / Grande / Ohana | +7 / +14 / +32 | +7 / +14 / **+36** |
| Tamaño Cafe (id 3) y Tamaño Cafe (id 7) | Grande | +2 | 0 (= usa +2) |
| Toppings | 14 opciones | +4 | +4 en 10, vacío en 4 |
| Frutas | 11 opciones | 0 | 0 |

Hay **dos grupos "Tamaño Cafe"** (ids 3 y 7): revisar si uno sobra (fuera de este plan).

## 3. Opciones evaluadas

| Opción | Ventajas | Desventajas | Veredicto |
|---|---|---|---|
| **A. Casilla por grupo "Precio distinto en PedidosYa"** | Explícita, la controla el admin, sirve si mañana otro grupo cambia de precio | Una columna y una migración | **Recomendada** |
| B. Detectar por nombre ("Tamaño…") | Sin migración | Se rompe al renombrar un grupo | No |
| C. Detectar por `max_selections = 1` | Sin migración | Implícita; un grupo de elección única no siempre es un tamaño | No |
| D. Borrar `delivery_price` de los toppings | Simple | Cambia datos y nada impide volver a cargarlos | No |

## 4. Decisiones

1. Nueva columna `option_groups.pedidosya_pricing` (boolean). Marcada = el grupo tiene precio propio en
   PedidosYa.
2. **Regla de precio** (frontend y backend a la vez): en PedidosYa, una opción usa `delivery_price` solo si su
   grupo está marcado **y** `delivery_price > 0`; si no, usa `additional_price`. En el local, siempre
   `additional_price`.
3. Los `delivery_price` que ya tienen los toppings **no se borran**: quedan en la base pero se ignoran.
4. El precio del tamaño sigue siendo **adicional** sobre el producto (bowl 30 + Ohana 36 en PedidosYa).
   Si se prefiriera un precio total por tamaño, es otro cambio (ver §8).
5. Compatibilidad con el POS offline: si el catálogo en caché no trae el campo, se aplica la regla anterior
   (`delivery_price > 0`) hasta que el catálogo se refresque.

## 5. Cambios

### 5.1 Base de datos
- Migración `add_pedidosya_pricing_to_option_groups_table`: columna boolean, default `false`.
  Marca en `true` los grupos cuyo nombre empieza por "Tamaño" o "Tamano" (sin distinguir mayúsculas): hoy
  Tamaño Acai y los dos Tamaño Cafe. **Cambia datos al instalarse: confirmar con el usuario antes de fusionar.**
  No toca ningún precio.

### 5.2 Backend
- `OptionGroup`: `fillable` y cast boolean. `OptionGroupRequest`: regla `['sometimes', 'boolean']`.
- `OptionGroupResource`: expone `pedidosya_pricing`. `OptionResource`: expone el valor del grupo en cada
  opción (`pedidosya_pricing`) sin consultas extra (el grupo ya está cargado al listar sus opciones).
- `SalePricingService::optionPrice()`: aplica la regla 4.2 leyendo el grupo de la opción.
  En `SaleSyncService::insertSaleItems()`, cargar las opciones con `optionGroup` (`Option::with('optionGroup')`)
  para no hacer una consulta por opción.
- `PedidosYaPricingController` (Tarifario): rechaza con 422 un `delivery_price` para opciones de grupos no
  marcados ("Este grupo cobra el mismo precio en PedidosYa").

### 5.3 Frontend
- `utils/channelPricing.js`: `optionPrice()` y `missingDeliveryPrice()` siguen la regla 4.2 y 4.5.
  Nueva función `optionHasChannelPrice(option)`.
- `Pos.vue` (banner) y `ModifierModal.vue`: el aviso "Sin precio PedidosYa" solo para opciones de grupos marcados.
- `OptionGroupForm.vue`: casilla **"🛵 Precio distinto en PedidosYa"** con ayuda "Úsalo para tamaños. Toppings y
  frutas cobran lo mismo en los dos canales".
- `OptionGroupDetail.vue`:
  - Modal "Editar opcional": el campo pasa a llamarse **"Precio adicional en PedidosYa (BOB)"**, se muestra solo si
    el grupo está marcado, y vacío = usa el precio local.
  - Lista de opciones: en grupos marcados, debajo del precio local, una línea `🛵 +36,00 BOB` (o "🛵 = local").
  - En grupos no marcados, una nota bajo el título: "Mismo precio en local y PedidosYa".
- Tarifario (`PedidosYaPricing.vue`): la sección pasa a llamarse **"Tamaños"** y solo lista grupos marcados.
  Nota al pie: "Toppings, frutas y demás opcionales cobran el mismo precio en PedidosYa".
  "Aplicar +X %" ya no toca opciones de grupos no marcados.
- `AdminSaleEditor.vue` (Control Diario): usa la misma `optionPrice()`; sin otros cambios.

## 6. Tests

1. Pedido PedidosYa con tamaño Ohana (grupo marcado, +36) → importe con +36, sin discrepancia.
2. Pedido PedidosYa con topping que tiene `delivery_price` 5 (grupo no marcado) → se cobra +4; un cobro de +5
   queda como discrepancia.
3. Venta local con tamaño → siempre +32.
4. Tarifario: guardar `delivery_price` de un topping → 422; de un tamaño → se guarda y se audita.
5. La migración marca solo los grupos "Tamaño…" y no cambia ningún precio.
6. Los tests existentes siguen en verde (`php artisan test`) y `npx vite build` compila.

## 7. Criterios de aceptación

- [ ] En Opcionales, el grupo de tamaños tiene la casilla marcada y muestra su precio PedidosYa; los toppings no
      piden ni muestran precio PedidosYa.
- [ ] En el POS en modo PedidosYa, un bowl con tamaño Ohana cobra 30 + 36; con toppings cobra el precio local
      del topping.
- [ ] El POS ya no avisa "Sin precio PedidosYa" por toppings.
- [ ] El Tarifario muestra solo productos y tamaños.
- [ ] Contraste: 0 textos bajo WCAG AA en Opcionales y Tarifario (tema claro y oscuro).

## 8. Fuera de alcance

- Precio **total** por tamaño (por ejemplo, "Ohana = Bs 66" en vez de "+36"). Requiere cambiar cómo se suma el
  precio en el POS, el ticket y el servidor.
- Limpiar los `delivery_price` de los toppings y unificar los dos grupos "Tamaño Cafe".

## 9. Forma de trabajo

- Sobre la rama `feat/precios-pedidosya` (aún no activada en la Mac), en commits nuevos: base de datos y regla,
  pantallas, documentación.
- Consultar `graphify-out/` antes de tocar código y ejecutar `graphify update .` al terminar.
- Respaldo de la base antes de migrar; un commit por tema con `CHANGELOG.md` y `docs/BITACORA.md`.
