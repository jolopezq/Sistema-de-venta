# Plan: que el efectivo y el QR cuadren con lo que recibes

> Versión 1, 2026-11-04. **Aprobado por el dueño (A a G) el 04/11.** Sale de la auditoría de octubre, hecha sobre el
> respaldo de producción del 03/11 (documento «auditoria-octubre-2026» del proyecto en claude.ai).

## 1. Qué pasó en octubre

| Diferencia | Qué pasó | ¿Se perdió plata? |
|---|---|---|
| Efectivo: Bs 171 | Compras pagadas con plata de la gaveta: fruta 17/10 (74), Sami (37), mitad de fruta 21/10 (50), delivery 22/10 (10). | No: se pagó a proveedores. |
| Efectivo: Bs 14 | La gaveta empezó el mes con 242 y terminó con 256 de cambio. | No: está en la gaveta. |
| Efectivo: −Bs 4 | La caja del 09/10 abrió con 253 cuando la del 08/10 dejó 249. | No: es un error de fondo. |
| **Efectivo: Bs 37** | Sin explicar. El único retiro que no es redondo es el de **Bs 37 del 22/10** (caja de Lourdes). | **Por confirmar.** |
| QR: Bs 260 | La venta **171026-0005** (20 Açaí Simple, «Clientes especial») se cargó en Control diario el 02/11 a las 02:57. Control diario usa la **hora del reloj** con la fecha elegida: quedó 17/10 02:57, antes de que abriera la caja del 17/10 (15:31), y no entró a ninguna caja. | Si no llegó al banco, sí. |
| QR: Bs 10 | El cliente del 22/10 pagó por QR la venta (30) y el delivery (10). El banco recibe 10 de más. | No. |
| **QR: Bs 30** | Sin explicar. Se ubica comparando el extracto día por día. | **Por confirmar.** |

El gasto «Sami» de Bs 37 y la fruta de Bs 130 se cargaron el 21/10 a las 22:38 mientras se pasaba la caja del
**02/10** (se abrió a las 22:36 y sus ventas se cargaron a las 22:44). Son gastos del 02/10 que quedaron con fecha del
21/10 por un error que ya se corrigió el 31/10. No tienen relación con el retiro de Bs 37 del 22/10.

**Otros problemas que encontró la auditoría:**
- Cajas abiertas sin cerrar: 14/10 (sin ventas) y 27/10 (2 ventas QR). Se pudo abrir la caja del día siguiente con la anterior abierta.
- Fondo mal cargado: 03/10 abrió con 0 y el fondo se registró como «ingreso extra» de 244; 09/10 con 4 de más. Nada avisa cuando el fondo no coincide con lo que quedó.
- Finanzas compara con lo **cobrado** en efectivo, no con lo que **debía llegar a la mano**. Por eso 181 parecían faltante.
- El Estado de Resultados no tiene línea para faltantes o sobrantes de caja.
- Octubre no tiene sueldos ni luz, agua o internet cargados, y nada lo avisa.
- En el detalle del Estado de Resultados, los egresos cargados después muestran la fecha de carga.

## 2. Correcciones en el sistema

Rama `fix/conciliacion-caja`, desde la versión publicada (`6d647c1`), para poder publicarla sin esperar a Asistencia.

| # | Corrección | Dónde |
|---|---|---|
| A | **Efectivo que debía llegar a la mano.** Debajo de «Efectivo» en «Dinero que entró»: cobrado − pagado con la gaveta − cambio que quedó ± saltos de fondo ± diferencias de cierre = **retirado de las cajas**. Avisa si hay cajas del mes sin cerrar. | Finanzas → Resumen |
| B | **Faltantes y sobrantes de caja** como línea del Estado de Resultados, con la diferencia de cada cierre en la fecha de su caja. | Finanzas → Estado de Resultados |
| C | **Una venta de un día pasado entra en la caja de ese día.** La hora por defecto queda dentro del horario de la caja; si igual queda fuera, el servidor la asigna a la caja de ese día; si ese día no tiene caja, se avisa antes de guardar. Finanzas y Control diario avisan las ventas sin caja del mes. | Control diario, servidor |
| D | **Cajas anteriores sin cerrar:** al abrir la caja se avisa «La caja del 27/10 sigue abierta» con el enlace para cerrarla; Control diario y Finanzas las listan. | Caja, Control diario, Finanzas |
| E | **Fondo distinto al que quedó:** al abrir una caja con un fondo distinto a lo que dejó la anterior, se pide el motivo y queda en auditoría. | Caja, Control diario |
| F | **Avisos de gastos que faltan:** si el mes no tiene sueldos o servicios (luz, agua, internet), Finanzas lo avisa. El detalle del Estado de Resultados muestra la fecha real de cada egreso. | Finanzas |
| G | **QR del banco al cerrar caja:** en el cierre se anota el QR que muestra la app del banco ese día; la diferencia con el sistema queda en el cierre y se suma en Finanzas. Así una diferencia de QR se ve el mismo día. Migración aditiva (una columna en `cash_register_sessions`). | Cierre de caja, Finanzas |

Pruebas: PHPUnit de cada regla (identidad del efectivo con cajas abiertas, saltos y diferencias; asignación de ventas
de días pasados; fondo con motivo; QR del banco), unitarias del frontend, E2E en la portátil de caja (1366 × 611) y
contraste AA. CHANGELOG y BITÁCORA en cada commit.

### Cómo se calcula «Efectivo que te debía llegar a la mano» (A)

En cada caja cerrada, retirado + queda = contado = fondo + ventas en efectivo − egresos en efectivo + ingresos en
efectivo + diferencia. Sumando las cajas cerradas del mes en orden:

```
retirado = ventas − egresos + ingresos + diferencias − (queda de la última − fondo de la primera) + saltos de fondo
```

La diferencia de cada caja se calcula con sus movimientos de ahora (contado − esperado actual), así la cuenta cierra
aunque se haya corregido algo después del cierre. Las cajas abiertas y las ventas sin caja se restan aparte. Octubre
en la copia de producción: 2.868 − 171 + 244 − 14 − 240 = **2.687**, lo retirado de las cajas (`CashDrawerService`).

## 3. Correcciones de datos (las hace el dueño desde la pantalla, con guía)

No se toca la base a mano. Cada paso queda en la auditoría.
1. **Venta 171026-0005 (Bs 260):** confirma si se cobró y por dónde. Si se cobró ese día, edita su hora dentro del 17/10 y entra a la caja del 17/10. Si no se cobró, se anula o se cambia el medio de pago.
2. **Cerrar las cajas del 14/10 y del 27/10** en Control diario, contando el mismo fondo con el que abrieron.
3. **Fecha de los gastos Sami (37) y fruta (130)** al 02/10. No cambia los totales de octubre.
4. **Completar octubre:** sueldos y servicios de octubre en Finanzas → Gastos; importar la semana PedidosYa del 26/10 al 01/11.
5. **Bs 37 y Bs 30:** preguntar a Lourdes por el retiro del 22/10 y comparar el extracto día por día en la planilla de conciliación.

## 4. Fuera de este plan
- Inventario: las compras no están vinculadas a insumos y el stock solo baja. Necesita su propio plan.
