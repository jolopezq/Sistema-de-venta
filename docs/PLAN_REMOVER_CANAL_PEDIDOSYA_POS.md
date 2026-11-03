# Plan: Remover selector manual de PedidosYa del POS y robustecer importación por lotes

## Contexto y Motivación
1. **Decisión operativa del dueño:** Los pedidos de PedidosYa se gestionan y se imprimen directamente en el dispositivo POS oficial de PedidosYa. Ya no es necesario ni deseable ingresarlos manualmente en la caja del local, pues toda la conciliación se realiza de forma automática por carpetas o documentos en el módulo de Finanzas.
2. **Optimización de pantalla en caja (Toshiba 1366 × 611):** Remover el selector `[ Local ]` / `[ PedidosYa ]` y el banner de aviso de `Pos.vue` recupera ~70px de espacio vertical en la columna del ticket, eliminando la necesidad de scroll vertical para ver el total y el botón de cobro.
3. **Robustez ante el error 500 en importación por carpeta (septiembre):** El endpoint de importación fallaba con Error 500 (`InvalidArgumentException: El archivo CSV no contiene la columna obligatoria: 'Nro de pedido'`) cuando el CSV utilizaba un delimitador distinto a coma (ej. `;` común en Excel en español) o caracteres BOM. Se requiere detección automática de delimitador y fallback elegante a la planilla Excel oficial (`invoice-*.xls`).

---

## Estructura de Cambios

### Backend
- `backend/app/Services/PedidosYaImportService.php`:
  - Agregar detección automática de delimitador (`detectDelimiter`: `,`, `;`, `\t`).
  - Saneamiento de BOM UTF-8 tanto a nivel de cabecera como en cada nombre de columna.
  - En `importSettlementFromFolder`: envolver el parseo de `$csvFile` en bloque de captura; si falla, registrar advertencia y recurrir a `$ordersXls` para no bloquear la importación del estado de cuenta ni los archivos adjuntos.
- `backend/app/Http/Controllers/PedidosYaSettlementController.php`:
  - Capturar `InvalidArgumentException` devolviendo respuesta JSON 422 controlada en lugar de excepción 500.
- `backend/tests/Feature/PedidosYaReadersTest.php` / `PedidosYaSettlementArchiveTest.php`:
  - Probar importación de CSV con delimitador `;` y con BOM.
  - Probar fallback a Excel cuando el CSV es inválido.

### Frontend
- `frontend/src/views/Pos.vue`:
  - Remover bloque template `<div class="channel-switch">...</div>` y `<div v-if="isPedidosYa" class="pya-banner">...</div>`.
  - Remover `<PriceOverrideModal>` y el modal de cambio de canal (`pendingChannel`).
  - Remover estados y funciones innecesarias: `pendingChannel`, `requestChannel`, `confirmChannelChange`, `itemsWithoutDeliveryPrice`, `priceOverrideItem`, `openPriceOverride`, `applyPriceOverride`, `clearPriceOverride`.
  - Simplificar `filteredProducts` y texto del botón de cobro (`Cobrar {{ formatMoney(cart.total) }}`).
  - Limpiar estilos CSS asociados a canales (`.channel-switch`, `.channel-btn`, `.pya-banner`, `.pos-ticket--pedidosya`).
- `frontend/e2e/descuento-caja.spec.js` y `frontend/e2e/checkout-portatil.spec.js`:
  - Ajustar pruebas que interactuaban con `.channel-btn--pya` en la vista de POS.

---

## Fases de Implementación

### Fase 1: Robustez Backend y Delimitadores CSV
1. Crear rama `feat/remover-canal-pedidosya-pos` desde `feat/importar-carpeta-pedidosya`.
2. Implementar `detectDelimiter` y saneamiento de BOM en `PedidosYaImportService.php`.
3. Implementar fallback a `OrderListReader` en `importSettlementFromFolder`.
4. Capturar `InvalidArgumentException` en `PedidosYaSettlementController.php` (422).
5. Escribir pruebas unitarias/feature en PHPUnit y verificar que pasen 444+ pruebas.

### Fase 2: Simplificación de la Vista POS (`Pos.vue`)
1. Limpiar template de `Pos.vue` (quitar switcher, banner, modales de canal).
2. Limpiar script de `Pos.vue` (quitar reactivos y handlers de PedidosYa manual).
3. Limpiar estilos CSS de `Pos.vue`.
4. Verificar que el ticket gane altura y se ajuste limpiamente a 1366 × 611.
5. Adaptar pruebas E2E afectadas.

### Fase 3: Verificación Completa
1. `cd backend && php artisan test` (100% verde).
2. `cd frontend && npm run test:unit` (100% verde).
3. `cd frontend && npx vite build` (build limpio).
4. Pruebas E2E de POS y portátiles (`npx playwright test`).
5. Auditoría de contraste si aplica.

### Fase 4: Documentación, Graphify y Despliegue
1. Actualizar `CHANGELOG.md` y `docs/BITACORA.md`.
2. Actualizar grafo de dependencias con `graphify update .`.
3. Commit convencional en español: `feat(pos): remover selector manual de pedidosya y robustecer importacion csv`.
4. Consultar al usuario sobre el despliegue al VPS en producción (`pos.ohana-pos.com`).
