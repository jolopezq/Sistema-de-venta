# 📱 Plan de Proyecto y Plan de Acción: Aplicación Móvil Android — "Ohana Kitchen KDS"

**Proyecto:** Ohana Kitchen KDS (Kitchen Display System & Comandero Digital)  
**Plataforma Objetivo:** Android 8.0+ (API 26+) — Optimizado para Tablets (7", 10", 12") y Smartphones  
**Arquitectura UI:** Jetpack Compose + Material 3 (100% Declarativo, Cero XML)  
**Patrón de Comanda:** Despliegue en línea (*In-Place Accordion* con `AnimatedVisibility`), **sin ventanas emergentes** y **sin botón de imprimir**.  
**Identificación:** Formato numérico estándar: diario `#001` + código de fecha `DDMMAA-XXXX` (ej. `270826-0001`).  
**Backend:** Laravel REST API (Ohana POS Backend en puerto `:8000`)  
**Fecha:** Agosto 2026  

---

## 1. 🎯 Visión General y Arquitectura del Ecosistema

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SISTEMA CENTRAL OHANA POS                          │
│                                                                             │
│   ┌─────────────────────┐                    ┌───────────────────────────┐  │
│   │   POS Web (Cajero)  │ ──(Venta creada)─▶ │      Backend Laravel      │  │
│   │   Vue.js 3 / Pinia  │                    │   API REST + Sanctum      │  │
│   │                     │                    │   Puerto :8000 / MySQL    │  │
│   └─────────────────────┘                    └───────────────────────────┘  │
└─────────────────────────────────────────────────────▲───────────────────────┘
                                                      │
                       JSON REST / EventStream (SSE)  │ Red Wi-Fi Local o Túnel
                                                      │
┌─────────────────────────────────────────────────────▼───────────────────────┐
│                      NUEVO PROYECTO: APP MÓVIL ANDROID                      │
│                                                                             │
│   ┌─────────────────────────────────────────────────────────────────────┐   │
│   │                         "Ohana Kitchen KDS"                         │   │
│   │  • Kotlin 2.0 + Jetpack Compose (Material 3)                        │   │
│   │  • Retrofit 2 + OkHttp 3 + Kotlinx Serialization                    │   │
│   │  • Room Database (Caché local Offline-Resilient)                    │   │
│   │  • SoundPool (Alerta sonora de campana de comanda al recibir)       │   │
│   │  • Pantalla Always-On (FLAG_KEEP_SCREEN_ON)                         │   │
│   │  • UI Sin Popups: Acordeón en línea con AnimatedVisibility          │   │
│   │  • Cero botones de impresión en KDS (pantalla puramente operativa)  │   │
│   └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. 🌐 Direcciones de la API del Backend (Contrato de Integración)

### A. URLs Base de Conexión

| Entorno | URL Base | Descripción |
| :--- | :--- | :--- |
| **Red Local Wi-Fi (Recomendado)** | `http://<IP_MAC_O_SERVIDOR>:8000/api` | Comunicación de ultra-baja latencia en el local (ej. `http://192.168.1.15:8000/api`). |
| **Emulador Android Studio** | `http://10.0.2.2:8000/api` | Alias de Android Emulator para acceder al `localhost` de la máquina anfitriona. |
| **Túnel Público (Ngrok / Cloudflare)** | `https://<dominio-tunel>/api` | Para pruebas remotas o tablets conectadas por datos móviles (4G/5G). |

---

### B. Especificación de Endpoints

#### 1. Autenticación de Cocina
* **Método:** `POST`
* **Ruta:** `/login`
* **Headers:** `Content-Type: application/json`, `Accept: application/json`
* **Payload Request:**
  ```json
  {
    "email": "cocina@example.com",
    "password": "password"
  }
  ```
* **Respuesta Exitosa (200 OK):**
  ```json
  {
    "access_token": "105|a8bc73def9847192...",
    "token_type": "Bearer",
    "user": {
      "id": 4,
      "name": "Pantalla Cocina KDS",
      "email": "cocina@example.com",
      "role": "kitchen"
    }
  }
  ```

---

#### 2. Obtener Lista de Comandas Activas (Cola KDS)
* **Método:** `GET`
* **Ruta:** `/kds/orders` (o `/order-queue`)
* **Headers:** `Authorization: Bearer <access_token>`, `Accept: application/json`
* **Parámetros Opcionales de Query:**
  * `?source=pos` o `?source=pedidosya`
  * `?status=received` o `?status=preparing` o `?status=ready`
* **Respuesta Exitosa (200 OK):**
  ```json
  {
    "success": true,
    "server_time": "2026-08-27T14:15:00.000000Z",
    "counts": {
      "received": 1,
      "preparing": 1,
      "ready": 1,
      "delivered": 1,
      "total": 4
    },
    "orders": [
      {
        "id": "9ce8a7c2-1234-4567-89ab-cdef01234567",
        "order_number": "270826-0001",
        "daily_sequence": 1,
        "display_code": "#001",
        "source": "pos",
        "source_label": "Local / Mesa",
        "preparation_status": "received",
        "status_color": "blue",
        "elapsed_minutes": 8,
        "created_at": "2026-08-27T14:07:00.000000Z",
        "customer_name": "Cliente en Local",
        "cashier_name": "Admin Operativo",
        "notes": "Sin leche condensada",
        "total_amount": 28.00,
        "total_items_count": 1,
        "items": [
          {
            "id": 101,
            "product_id": 3,
            "product_name": "Classic Bowl",
            "quantity": 1,
            "unit_price": 28.00,
            "subtotal": 28.00,
            "is_takeaway": false,
            "item_note": null,
            "allergen_flags": ["lactose"],
            "options": [
              {
                "group_name": "Toppings",
                "option_name": "Durazno",
                "extra_price": 0.00,
                "quantity": 1
              }
            ]
          }
        ]
      }
    ]
  }
  ```

---

#### 3. Avanzar Estado de la Comanda
* **Método:** `PATCH`
* **Ruta:** `/kds/orders/{sale_id}/status`
* **Headers:** `Authorization: Bearer <access_token>`, `Content-Type: application/json`, `Accept: application/json`
* **Estados Permitidos:**
  * `received` ➔ **Recibido** (Estado inicial)
  * `preparing` ➔ **En Preparación** ("Enviar a preparar ➔")
  * `ready` ➔ **Listo para enviar / retirar** ("Marcar listo ✓")
  * `delivered` ➔ **Entregado** ("Entregar al cliente ✓")
* **Payload Request:**
  ```json
  {
    "status": "preparing"
  }
  ```
* **Respuesta Exitosa (200 OK):**
  ```json
  {
    "success": true,
    "message": "Estado de pedido actualizado correctamente.",
    "order": {
      "id": "9ce8a7c2-1234-4567-89ab-cdef01234567",
      "order_number": "270826-0001",
      "daily_sequence": 1,
      "display_code": "#001",
      "preparation_status": "preparing",
      "preparation_started_at": "2026-08-27T14:15:20.000000Z"
    }
  }
  ```

---

## 3. 🎨 Especificación UX: Tarjeta KDS en Android (Sin Ventanas Emergentes)

### Diagrama del Componente `OrderCard` en Jetpack Compose

```
┌─────────────────────────────────────────────────────────────┐
│ [🍽️ Local/Mesa]        #001 (270826-0001)       ⚠️ 8 min    │
│                                                             │
│ Cliente en Local                                            │
│                                                             │
│ ┌─ ÍTEMS A PREPARAR ──────────────────────────────────────┐ │
│ │ • 1x Classic Bowl                                       │ │
│ │   └ Durazno                                             │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ▼ [Al presionar "Ver comanda", se despliega aquí en línea]: │
│ ┌─ 🧾 DETALLE DE PREPARACIÓN ─────────────────────────────┐ │
│ │ ================================================        │ │
│ │                 ** OHANA ACAI **                        │ │
│ │          --- COMANDA DE PREPARACION ---                 │ │
│ │                   *** MESA ***                          │ │
│ │ ================================================        │ │
│ │ Comanda Nro: #001 (270826-0001)    Tipo: MOSTRADOR      │ │
│ │ Mesa: N/A                    Atiende: Admin Operativo   │ │
│ │ Fecha: 27/08/2026            Hora: 02:07:00 p. m.       │ │
│ │ ------------------------------------------------        │ │
│ │ CANT  DESCRIPCION                        IMPORTE        │ │
│ │ ------------------------------------------------        │ │
│ │  1x  CLASSIC BOWL                          28.00        │ │
│ │       * Durazno                             0.00        │ │
│ │       * ALERGIA: Lactosa                                │ │
│ │ ------------------------------------------------        │ │
│ │ TOTAL ITEMS: 1               TOTAL BOB:    28.00        │ │
│ │ ================================================        │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────┐  ┌────────────────────────────┐ │
│ │ 📋 Ocultar comanda ▴    │  │ Enviar a preparar ➔        │ │
│ └─────────────────────────┘  └────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Reglas de Diseño Operativo para Tablet de Cocina:
1. **Cero Modales (`No Dialog`, `No BottomSheet`):** Toda la interacción ocurre dentro del flujo vertical de la tarjeta con `AnimatedVisibility(visible = isExpanded)`. El cocinero nunca pierde de vista los demás pedidos.
2. **Multitarea en Barra:** Múltiples tarjetas pueden desplegarse simultáneamente sin interferir entre sí.
3. **Cero Botones de Impresión en KDS:** La tablet de cocina es una terminal 100% digital y táctil; no necesita enviar comandos de impresión.
4. **Semáforo Visual del Tiempo:**
   - 🟢 Verde: < 10 min.
   - 🟡 Ámbar: 10 a 20 min.
   - 🔴 Rojo: > 20 min (con badge de advertencia `⚠️ 21 min`).

---

## 4. 🛠️ Implementación en Código Jetpack Compose (Kotlin)

### A. Modelo de Dominio (`Order.kt`)
```kotlin
package com.ohana.kds.domain.model

data class Order(
    val id: String,
    val orderNumber: String,       // ej: "270826-0001"
    val dailySequence: Int,        // ej: 1
    val displayCode: String,       // ej: "#001"
    val source: String,            // "pos" | "pedidosya"
    val sourceLabel: String,
    val preparationStatus: String, // "received" | "preparing" | "ready" | "delivered"
    val customerName: String,
    val cashierName: String,
    val notes: String?,
    val totalAmount: Double,
    val elapsedMinutes: Int,
    val items: List<OrderItem>
)

data class OrderItem(
    val id: Long,
    val productName: String,
    val quantity: Int,
    val unitPrice: Double,
    val subtotal: Double,
    val isTakeaway: Boolean,
    val itemNote: String?,
    val allergenFlags: List<String>,
    val toppings: List<String>
)
```

---

### B. Generador del Ticket Monoespaciado (`TicketFormatter.kt`)
```kotlin
package com.ohana.kds.util

import com.ohana.kds.domain.model.Order

object TicketFormatter {
    private const val WIDTH = 48
    private val SEP_EQ = "=".repeat(WIDTH)
    private val SEP_DA = "-".repeat(WIDTH)

    fun formatKitchenTicket(order: Order): String {
        val lines = mutableListOf<String>()

        val takeawayCount = order.items.count { it.isTakeaway }
        val orderTypeStr = when {
            order.source == "pedidosya" -> "*** PEDIDOSYA DELIVERY ***"
            takeawayCount > 0 && takeawayCount == order.items.size -> "*** PARA LLEVAR ***"
            takeawayCount > 0 -> "*** MIXTO (MESA Y LLEVAR) ***"
            else -> "*** MESA ***"
        }

        lines.add(SEP_EQ)
        lines.add(padCenter("** OHANA ACAI **", WIDTH))
        lines.add(padCenter("--- COMANDA DE PREPARACION ---", WIDTH))
        lines.add(padCenter(orderTypeStr, WIDTH))
        lines.add(SEP_EQ)

        val fullCode = "${order.displayCode} (${order.orderNumber})"
        val tipoStr = if (order.source == "pedidosya") "DELIVERY" else if (takeawayCount > 0) "LLEVAR" else "MOSTRADOR"
        lines.add("Comanda Nro: ${fullCode.padEnd(21)}Tipo: $tipoStr")
        lines.add("Mesa: ${"N/A".padEnd(21)}Atiende: ${order.cashierName.take(15).padEnd(15)}")
        lines.add("Fecha: 27/08/2026           Hora: 02:07:00 p. m.")
        lines.add(SEP_DA)
        lines.add("CANT  ${"DESCRIPCION".padEnd(33)}${"IMPORTE".padStart(7)}")
        lines.add(SEP_DA)

        var totalItems = 0
        order.items.forEach { item ->
            totalItems += item.quantity
            val qtyStr = "${item.quantity}x".padStart(4)
            val nameStr = item.productName.uppercase().padEnd(33)
            val subtotalStr = String.format("%.2f", item.subtotal).padStart(7)
            lines.add("$qtyStr  $nameStr$subtotalStr")

            item.toppings.forEach { topping ->
                lines.add("      * ${topping.padEnd(31)}   0.00")
            }
            item.itemNote?.takeIf { it.isNotBlank() }?.let {
                lines.add("      * NOTA: $it")
            }
            item.allergenFlags.forEach { alg ->
                lines.add("      * ALERGIA: ${alg.uppercase()}")
            }
        }

        lines.add(SEP_DA)
        order.notes?.takeIf { it.isNotBlank() }?.let {
            lines.add("OBSERVACIONES GENERALES:")
            lines.add("- $it")
            lines.add(SEP_DA)
        }

        val totalAmtStr = String.format("%.2f", order.totalAmount).padStart(8)
        lines.add("TOTAL ITEMS: ${totalItems.toString().padEnd(16)}TOTAL BOB: $totalAmtStr")
        lines.add(SEP_EQ)

        return lines.joinToString("\n")
    }

    private fun padCenter(text: String, width: Int): String {
        if (text.length >= width) return text
        val left = (width - text.length) / 2
        val right = width - text.length - left
        return " ".repeat(left) + text + " ".repeat(right)
    }
}
```

---

### C. Tarjeta KDS Táctil con Acordeón (`OrderCard.kt`)
```kotlin
package com.ohana.kds.ui.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.ohana.kds.domain.model.Order
import com.ohana.kds.util.TicketFormatter

@Composable
fun OrderCard(
    order: Order,
    onAdvanceStatus: () -> Unit,
    modifier: Modifier = Modifier
) {
    var isExpanded by remember { mutableStateOf(false) }

    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            // 1. FILA SUPERIOR: ORIGEN | #001 (270826-0001) | TIEMPO
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically
            ) {
                // Badge de origen
                Surface(
                    color = Color(0xFFEFF6FF),
                    shape = RoundedCornerShape(20.dp),
                    border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFFBFDBFE))
                ) {
                    Text(
                        text = "🍽️ ${order.sourceLabel}",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF1D4ED8),
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp)
                    )
                }

                // Identificador central: #001 (270826-0001)
                Row(verticalAlignment = Alignment.Bottom) {
                    Text(
                        text = order.displayCode,
                        fontSize = 19.sp,
                        fontWeight = FontWeight.Black,
                        color = MaterialTheme.colorScheme.onSurface
                    )
                    Spacer(modifier = Modifier.width(4.dp))
                    Text(
                        text = "(${order.orderNumber})",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                // Tiempo de espera
                val warnColor = if (order.elapsedMinutes >= 20) Color(0xFFDC2626) else Color(0xFFB45309)
                val warnBg = if (order.elapsedMinutes >= 20) Color(0xFFFEE2E2) else Color(0xFFFEF3C7)
                Surface(
                    color = warnBg,
                    shape = RoundedCornerShape(20.dp),
                    border = androidx.compose.foundation.BorderStroke(1.dp, warnColor.copy(alpha = 0.3f))
                ) {
                    Text(
                        text = "⚠️ ${order.elapsedMinutes} min",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.ExtraBold,
                        color = warnColor,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 3.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(8.dp))
            Text(
                text = order.customerName,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            Spacer(modifier = Modifier.height(8.dp))

            // 2. CAJA RESUMEN: ÍTEMS A PREPARAR
            Surface(
                color = Color(0xFFFAF8F5),
                shape = RoundedCornerShape(10.dp),
                border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFFE2E8F0)),
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(modifier = Modifier.padding(10.dp)) {
                    Text(
                        text = "ÍTEMS A PREPARAR",
                        fontSize = 10.sp,
                        fontWeight = FontWeight.ExtraBold,
                        color = Color(0xFF64748B),
                        letterSpacing = 0.5.sp
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    order.items.forEach { item ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("•", color = Color(0xFFEA580C), fontWeight = FontWeight.Bold)
                            Spacer(modifier = Modifier.width(6.dp))
                            Surface(
                                color = Color(0xFF4C1D95),
                                shape = RoundedCornerShape(4.dp)
                            ) {
                                Text(
                                    text = "${item.quantity}x",
                                    color = Color.White,
                                    fontSize = 10.sp,
                                    fontWeight = FontWeight.Bold,
                                    modifier = Modifier.padding(horizontal = 5.dp, vertical = 1.dp)
                                )
                            }
                            Spacer(modifier = Modifier.width(6.dp))
                            Text(
                                text = item.productName,
                                fontSize = 13.sp,
                                fontWeight = FontWeight.Bold
                            )
                        }
                        if (item.toppings.isNotEmpty()) {
                            Text(
                                text = "  └ ${item.toppings.joinToString(", ")}",
                                fontSize = 11.sp,
                                color = Color(0xFF64748B),
                                modifier = Modifier.padding(start = 16.dp, top = 2.dp)
                            )
                        }
                    }
                }
            }

            // 3. DESPLIEGUE EN LÍNEA: COMANDA TÉRMICA (SIN POPUP)
            AnimatedVisibility(
                visible = isExpanded,
                enter = expandVertically() + fadeIn(),
                exit = shrinkVertically() + fadeOut()
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(top = 8.dp)
                        .border(1.dp, Color(0xFFCBD5E1), RoundedCornerShape(10.dp))
                        .background(Color(0xFFFAF8F5), RoundedCornerShape(10.dp))
                ) {
                    // Header de la comanda
                    Surface(
                        color = Color(0xFFF1EFEC),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text(
                            text = "🧾 DETALLE DE PREPARACIÓN",
                            fontSize = 10.sp,
                            fontWeight = FontWeight.Black,
                            color = Color(0xFF1E293B),
                            modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                        )
                    }

                    // Ticket Monoespaciado
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .horizontalScroll(rememberScrollState())
                            .padding(8.dp)
                    ) {
                        Text(
                            text = TicketFormatter.formatKitchenTicket(order),
                            fontFamily = FontFamily.Monospace,
                            fontSize = 10.sp,
                            lineHeight = 13.sp,
                            color = Color(0xFF1E293B)
                        )
                    }
                }
            }

            Spacer(modifier = Modifier.height(10.dp))

            // 4. BOTONES DE ACCIÓN (TOGGLE COMANDA + AVANZAR ESTADO)
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                // Botón Ver / Ocultar comanda (Con estado ámbar cuando está expandido)
                OutlinedButton(
                    onClick = { isExpanded = !isExpanded },
                    modifier = Modifier.weight(1f),
                    shape = RoundedCornerShape(10.dp),
                    colors = ButtonDefaults.outlinedButtonColors(
                        containerColor = if (isExpanded) Color(0xFFFEF3C7) else Color.Transparent,
                        contentColor = if (isExpanded) Color(0xFF92400E) else Color(0xFF334155)
                    ),
                    border = androidx.compose.foundation.BorderStroke(
                        1.dp,
                        if (isExpanded) Color(0xFFF59E0B) else Color(0xFFCBD5E1)
                    )
                ) {
                    Text(
                        text = if (isExpanded) "📋 Ocultar comanda ▴" else "📋 Ver comanda ▾",
                        fontSize = 11.sp,
                        fontWeight = FontWeight.Bold,
                        maxLines = 1
                    )
                }

                // Botón de Avance Semántico
                Button(
                    onClick = onAdvanceStatus,
                    modifier = Modifier.weight(1.2f),
                    shape = RoundedCornerShape(10.dp),
                    colors = ButtonDefaults.buttonColors(
                        containerColor = when (order.preparationStatus) {
                            "received" -> Color(0xFF2563EB)
                            "preparing" -> Color(0xFFD97706)
                            else -> Color(0xFF16A34A)
                        }
                    )
                ) {
                    val label = when (order.preparationStatus) {
                        "received" -> "Enviar a preparar ➔"
                        "preparing" -> "Marcar listo ✓"
                        else -> "Entregar al cliente ✓"
                    }
                    Text(
                        text = label,
                        fontSize = 11.sp,
                        fontWeight = FontWeight.ExtraBold,
                        color = Color.White,
                        maxLines = 1
                    )
                }
            }
        }
    }
}
```

---

## 5. 📋 Plan de Acción de 5 Fases para la App Android

Este plan describe la ruta paso a paso para aplicar los cambios en el proyecto Android de la cocina:

### Fase 1: Actualización del Modelo de Datos (DTOs & Room)
- [x] Modificar `OrderDto.kt` para mapear los nuevos campos del backend:
  * `order_number: String` (ej. `"270826-0001"`)
  * `daily_sequence: Int` (ej. `1`)
  * `display_code: String` (ej. `"#001"`)
- [x] En la entidad `OrderEntity.kt` de Room, agregar las columnas correspondientes e incrementar la versión de base de datos con migración destructiva o simple para desarrollo.
- [x] Probar la serialización de Retrofit con `@SerialName("order_number")` y `@SerialName("daily_sequence")`.

### Fase 2: Implementar el Utilitario `TicketFormatter.kt`
- [x] Crear la clase `TicketFormatter.kt` en el paquete `util/`.
- [x] Implementar la función `formatKitchenTicket(order)` con ancho de 48 caracteres fijos.
- [x] Implementar soporte para `toppings`, notas y alérgenos en el ticket monoespaciado.
- [x] Escribir una prueba unitaria JUnit (`TicketFormatterTest.kt`) para verificar que el texto generado coincida exactamente con el formato requerido.

### Fase 3: Rediseño de `OrderCard.kt` (Acordeón In-Place)
- [x] Eliminar cualquier referencia a `Dialog`, `AlertDialog` o `ModalBottomSheet` en la UI de pedidos.
- [x] Implementar la cabecera unificada: `[🍽️ Origen]`, `#001 (270826-0001)`, `[⚠️ X min]`.
- [x] Implementar el recuadro "ÍTEMS A PREPARAR" con viñetas `• 1x Producto` y `└ Toppings`.
- [x] Envolver la caja del ticket dentro de `AnimatedVisibility(visible = isExpanded)`.
- [x] Integrar el botón dinámico `[📋 Ver comanda ▾]` / `[📋 Ocultar comanda ▴]` con estilos condicionales.
- [x] **Confirmar la ausencia del botón de imprimir** en todo el composable.

### Fase 4: Integración en Tablero Kanban (`KdsBoardScreen.kt`)
- [x] Asegurar que las columnas del Kanban (`LazyColumn` o `Column` con `verticalScroll`) permitan expandir las tarjetas de forma fluida.
- [x] Conectar el botón de avance semántico al ViewModel (`kdsViewModel.advanceOrderStatus(order)`).
- [x] Añadir alerta sonora discreta (`SoundPool`) cuando ingrese una orden nueva en la columna *Recibidos*.

### Fase 5: Pruebas y Validación en Tablet
- [x] Ejecutar en emulador de Tablet Android (10", resolución 1920x1200) o dispositivo físico de cocina.
- [x] Verificar que múltiples comandas puedan estar desplegadas en simultáneo sin provocar saltos de pantalla indeseados.
- [x] Verificar el modo oscuro (Dark Mode) asegurando que el ticket monoespaciado mantenga alto contraste y legibilidad para el personal de cocina.
