# Plan: completar automáticamente el monto del pago dividido (Cobro)

> **Archivo a modificar:** [CheckoutModal.vue](../frontend/src/components/CheckoutModal.vue) (solo `<script>` y los dos `v-model` de montos; sin cambios de diseño).
> **Origen:** caso visto en caja (total Bs 28; Efectivo 28 + QR 8; Recibe 20 → "faltan Bs 8,00 en efectivo" y el botón Cobrar bloqueado) y análisis adjunto.
> **Reglas:** [AGENTS.md](../AGENTS.md). **Rama:** `feat/pos-caja-arranque`. **Estado (2026-10-20):** aprobado y ejecutado (fases 0–3 y 5); pendiente la prueba manual con copia de la base (fase 4). Decisión del dueño: el auto-completado también aplica con 3 métodos.

---

## 1. Análisis del caso

### 1.1 Qué pasó (verificado contra el código)
1. `startSplit()` deja la primera línea en el total (28) y agrega la segunda en 0.
2. El cajero escribe 8 en QR. **Nada recalcula la línea de Efectivo**: sigue en 28 (suma 36).
3. `cashShort` compara la **línea** de efectivo (28) con lo recibido (20): `28 − 20 = 8` → `isReady` = false → mensaje y botón bloqueados.
4. Para el cajero el monto "no se completa": ya tiene 8 en QR + 20 en mano = 28 y la pantalla le dice que falta.

**El diagnóstico del análisis adjunto es correcto.** Matices que añado:
- No es solo cosmético: si el cajero dejara Recibe en 28, el sistema aceptaría 28 + 8 = 36 y mostraría **vuelto Bs 8**, que el cliente no debe recibir (ya pagó 8 por QR). Es decir, el mismo desajuste puede producir un **cobro de más y un vuelto falso**, no solo un bloqueo.
- El servidor **no valida** que los pagos sumen el total (`SyncSalesRequest` solo exige método válido y `amount ≥ 0`). **El único control es esta pantalla**, por lo que la lógica debe quedar coherente y probada.
- La "Opción C" del análisis (prellenar al pulsar "Dividir" con lo que ya se escribió en Recibe) casi no aporta: lo normal es dividir primero y teclear después.

### 1.2 Evaluación de las opciones del análisis

| Opción | Veredicto |
|---|---|
| **A. Auto-balanceo bidireccional (2 métodos)** | **Adoptar.** Es el 99 % de los casos, cero clics extra, y evita el error de raíz. Con ajustes (ver §2). |
| B. Botón "Completar restante" | **No ahora.** Añade ancho y un clic; en 1366 × 611 el pago dividido ya está al límite. Solo útil con 3 métodos; puede ser una mejora posterior. |
| C. Sugerencia al pulsar "Dividir" | **Descartar** (aporta poco, ver arriba). |
| Blindaje de `cashNeededAmount = max(0, total − otros)` | **Adoptar con corrección**: usar `min(líneaEfectivo, max(0, total − noEfectivo))`. La fórmula original, si la línea de efectivo es menor a lo necesario (p. ej. Efectivo 10 + QR 8 de 28), pediría 20 en "Justo" y en Recibe mientras la línea dice 10: otra incoherencia. |

---

## 2. Diseño de la solución

### 2.1 Reglas de balanceo
- **Dos líneas (caso normal):** al editar el monto de una, la otra pasa a `total − valor` (mínimo 0, redondeo a 2 decimales). Funciona en ambos sentidos: QR 8 → Efectivo 20; Efectivo 20 → QR 8.
- **Tres líneas:** se edita una línea y **solo** se completa la línea que el cajero **aún no tocó** (indicador interno `touched`), si es una sola; si no, no se mueve nada y el resultado sigue mostrando "Falta cubrir Bs X". Nunca se pisa un monto que el cajero escribió a mano.
- **Topes:** ninguna línea baja de 0 ni supera el total. Campo vacío o `NaN` cuenta como 0 mientras se escribe, sin romper el input (no se reescribe el campo que se está tecleando).
- **Al agregar una línea** (`addPayment`) ya se rellena con el restante: se mantiene.
- **Al quitar una línea** y quedar 2: la línea restante no editada absorbe el saldo; si queda 1: cubre el total (ya existe).
- **Método cambiado en una línea** (select): no cambia montos.

### 2.2 Efectivo coherente (blindaje)
Definir una sola cantidad de verdad:
```
noEfectivo  = Σ montos de líneas que no son efectivo
cashDue     = línea efectivo ? min(montoLíneaEfectivo, max(0, total − noEfectivo)) : 0
```
Usar `cashDue` en: `cashNeededAmount` (lo que sincroniza Recibe), `billSuggestions`, botón "Justo", `cashShort` (`max(0, cashDue − recibido)`) y el cálculo de `change`.
`handleConfirm` conserva su descuento del exceso sobre el efectivo (ya consistente), así el arqueo no cuenta de más.

### 2.3 Sobrepago en QR/tarjeta
Con el tope de §2.1 una línea no efectivo no puede superar el total, así que desaparece el caso "QR 40 para 28" que hoy muestra un vuelto sin efectivo del que devolverlo.

### 2.4 Texto
Con pago dividido, la etiqueta **"Recibe"** pasa a **"Recibe (efectivo)"** para que quede claro que no incluye el QR. El texto del resultado no cambia.

### 2.5 Implementación técnica
- Cambiar los montos a manejador explícito: `@input="onAmountInput(index, $event.target.value)"` (en lugar de un `watch` profundo sobre `payments`, para evitar bucles y no pelear con el cursor). Mantener `v-model` en el campo y leer `$event.target.value`; verificar con test que no hay doble actualización.
- Aritmética en **centavos enteros** internamente (`Math.round(x*100)`) para evitar 0,1 + 0,2.
- Sin tocar: `cart.checkout`, formato de `payments`, backend.

---

## 3. Pruebas

### 3.1 Playwright (extender `frontend/e2e/checkout-portatil.spec.js`, proyecto "Portátil de caja")
Mock con producto de Bs 28 (en el actual el total es 38,50; añadir un segundo producto o cambiar el precio en un test).
1. **Caso de la captura:** total 28 → Dividir → QR = 8 → Efectivo pasa a 20, Recibe sigue en 20, `Cobrar` habilitado, sin mensaje de falta.
2. **Inverso:** Efectivo = 20 → QR pasa a 8.
3. **Recibe mayor:** Efectivo 20 + QR 8, Recibe 50 → vuelto 30; con Efectivo 28 forzado + QR 8 ya no es posible (se rebalancea).
4. **Decimales:** total 38,50, QR 12,10 → Efectivo 26,40 (sin 26,400000001).
5. **Campo vacío y tope:** borrar el monto → la otra línea = total; escribir más que el total → se limita; sin NaN ni negativos.
6. **Tres métodos:** editar una línea completa solo la no tocada; con las tres tocadas aparece "Falta cubrir" y no se mueve nada.
7. **Quitar línea:** de 3 a 2 y de 2 a 1 deja sumas correctas.
8. **Payload enviado:** capturar lo que `cart.checkout` recibe (o `/sales/sync`) y comprobar `payments = [{cash, 20}, {qr, 8}]` y `change = 0`.
9. **Regresión de diseño:** los tests existentes (cero scroll en 1366 × 611, 641, 697, 768, 1280 × 720, 125 %, 390 × 844) deben seguir verdes; el cambio de etiqueta no debe causar desborde.

### 3.2 Prueba de lógica pura
Como `cashDue`/balanceo son cálculos, extraerlos a `frontend/src/utils/splitPayments.js` (funciones puras: `rebalance(payments, index, value, total)`, `cashDue(payments, total)`) con una prueba unitaria rápida, además de los E2E. Reduce el riesgo de errores de redondeo.

### 3.3 Prueba manual contra copia de la base (AGENTS.md)
```bash
cp backend/database/database.sqlite <scratch>/copia.sqlite
cd backend/public && DB_DATABASE=<scratch>/copia.sqlite php -S 127.0.0.1:8001 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
cd frontend && VITE_API_URL=http://127.0.0.1:8001/api npx vite --port 5174 --strictPort
```
Vender Bs 28 con Efectivo 20 + QR 8; confirmar en la copia que se guardan **dos pagos** (20 y 8), que el arqueo de efectivo suma 20 y que el vuelto es 0. Repetir con Recibe 50 (vuelto 30). Nunca contra la base real.

---

## 4. Fases

| Fase | Trabajo |
|---|---|
| 0 | Escribir los tests 1, 2 y 8 y ver que **fallan hoy** (reproducen la captura). |
| 1 | Extraer funciones puras + prueba unitaria. |
| 2 | Integrar en `CheckoutModal.vue` (`onAmountInput`, `cashDue`, etiqueta). |
| 3 | Completar tests 3–7 y 9; build; revisar capturas 1366 × 611 claro y oscuro. |
| 4 | Prueba manual con copia de la base. |
| 5 | `CHANGELOG.md` ("Sin publicar"), `docs/BITACORA.md`, `graphify update .`. |

## 5. Riesgos y decisiones abiertas

| Riesgo / duda | Mitigación o pregunta |
|---|---|
| El cajero quiere un reparto distinto y la otra línea "se mueve sola" | Es el comportamiento esperado en 2 líneas; se ve en pantalla y se puede reeditar cualquiera. **Pregunta:** ¿lo quieres también con 3 métodos (hoy solo completa la línea no tocada)? |
| Doble disparo con `v-model` + `@input` | Test específico (§3.1-4/5) y lectura de `$event.target.value`. |
| Más cambios en un modal ya ajustado al milímetro | Sin cambio de altura/ancho; el test de "cero scroll" se re-ejecuta. |
| Datos históricos con pagos mal sumados (ya guardados) | Fuera de alcance; si se sospecha, revisar con consulta de solo lectura en una copia. |

## 6. Archivos
- Modificar: `frontend/src/components/CheckoutModal.vue`.
- Crear: `frontend/src/utils/splitPayments.js` (+ prueba unitaria).
- Extender: `frontend/e2e/checkout-portatil.spec.js`.
- Actualizar: `CHANGELOG.md`, `docs/BITACORA.md`, grafo.
