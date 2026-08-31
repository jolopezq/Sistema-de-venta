<script setup>
import { computed, useAttrs } from 'vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({
  show: Boolean,
  sale: Object
});

const emit = defineEmits(['close', 'next']);

const attrs = useAttrs();
const auth = useAuthStore();

const hasNextAction = computed(() => Boolean(attrs.onNext));

const allergenTextMap = {
  'lactose': 'Lactosa',
  'gluten': 'Gluten',
  'almond': 'Almendras',
  'fruit': 'Fruta',
  'egg': 'Huevo',
  'peanut': 'Maní'
};

const padR = (str, len) => str.length > len ? str.substring(0, len) : str.padEnd(len, ' ');
const padL = (str, len) => str.length > len ? str.substring(0, len) : str.padStart(len, ' ');
const padC = (str, len) => {
  if (str.length >= len) return str.substring(0, len);
  const left = Math.floor((len - str.length) / 2);
  const right = len - str.length - left;
  return ' '.repeat(left) + str + ' '.repeat(right);
};

const ticketText = computed(() => {
  if (!props.sale) return '';
  const sale = props.sale;
  
  const width = 48;
  const sepEq = '='.repeat(width);
  const sepDa = '-'.repeat(width);
  
  let lines = [];
  
  const takeawayCount = sale.items ? sale.items.filter(i => i.is_takeaway).length : 0;
  const totalCount = sale.items ? sale.items.length : 0;
  let orderTypeStr = "*** MESA ***";
  if (takeawayCount > 0 && takeawayCount === totalCount) {
    orderTypeStr = "*** PARA LLEVAR ***";
  } else if (takeawayCount > 0) {
    orderTypeStr = "*** MIXTO (MESA Y LLEVAR) ***";
  }

  lines.push(sepEq);
  lines.push(padC("** OHANA ACAI **", width));
  lines.push(padC("--- COMANDA DE PREPARACION ---", width));
  lines.push(padC(orderTypeStr, width));
  lines.push(sepEq);
  
  // Número de orden formateado
  let orderNumStr = '';
  if (sale.daily_sequence) {
    const seqStr = `#${String(sale.daily_sequence).padStart(3, '0')}`;
    orderNumStr = seqStr;
    if (sale.order_number) {
      orderNumStr += ` (${sale.order_number})`;
    }
  } else if (sale.order_number) {
    orderNumStr = sale.order_number;
  } else {
    orderNumStr = `#${sale.id ? sale.id.split('-')[0].toUpperCase() : '0000'}`;
  }

  const tipoStr = sale.source === 'pedidosya' ? 'DELIVERY' : (takeawayCount > 0 && takeawayCount === totalCount ? 'LLEVAR' : 'MOSTRADOR');
  lines.push(`Comanda Nro: ${padR(orderNumStr, 22)}Tipo: ${tipoStr}`);
  
  const cashierName = sale.cashier?.name || (auth.user && auth.user.name) || 'Cajero';
  lines.push(`Mesa: ${padR('N/A', 23)}Atiende: ${padR(cashierName.substring(0, 15), 15)}`);
  
  const d = sale.created_at ? new Date(sale.created_at) : new Date();
  const dateStr = d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit', year: 'numeric' });
  const timeStr = d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  
  lines.push(`Fecha: ${padR(dateStr, 22)}Hora: ${timeStr}`);
  lines.push(sepDa);
  
  lines.push(`CANT  ${padR('DESCRIPCION', 33)}${padL('IMPORTE', 7)}`);
  lines.push(sepDa);
  
  let totalItems = 0;
  
  if (sale.items) {
    for (const item of sale.items) {
      const itemQty = Number(item.quantity) || 1;
      totalItems += itemQty;
      let baseName = (item.name || item.product?.name || 'Item').toUpperCase();
      let sizeMod = null;
      let otherMods = [];

      // Unificar modificadores de IndexedDB o Laravel API
      let itemOptions = [];
      if (item.modifiers && Array.isArray(item.modifiers) && item.modifiers.length > 0) {
        itemOptions = item.modifiers;
      } else {
        const apiOpts = item.sale_item_options || item.saleItemOptions;
        if (apiOpts && Array.isArray(apiOpts) && apiOpts.length > 0) {
          itemOptions = apiOpts.map(o => ({
            group_name: o.option_group?.name || o.optionGroup?.name || '',
            option_name: o.option_name_snapshot || o.option?.name || '',
            quantity: o.quantity || 1,
            price: o.additional_price_snapshot || o.option?.additional_price || 0
          }));
        }
      }

      if (item.is_takeaway) {
        baseName += ` (LLEVAR)`;
      } else {
        baseName += ` (MESA)`;
      }

      const qtyStr = padL(`${itemQty}x`, 4);
      const nameStr = padR(baseName, 33);
      
      const itemSubtotal = Number(item.subtotal) || (Number(item.unit_price || 0) * itemQty);
      const mainSubtotalStr = padL(itemSubtotal.toFixed(2), 7);
      lines.push(`${qtyStr}  ${nameStr}${mainSubtotalStr}`);
      
      if (itemOptions.length > 0) {
        for (const mod of itemOptions) {
          const label = mod.quantity > 1 ? `${mod.quantity}x ${mod.option_name}` : mod.option_name;
          const modNameStr = padR(label, 31);
          const mPrice = (Number(mod.price) || 0) * (mod.quantity || 1);
          const modTotal = mPrice * itemQty;
          const modPriceStr = padL(modTotal.toFixed(2), 7);
          lines.push(`      * ${modNameStr}${modPriceStr}`);
        }
      }
      
      const itemNote = item.item_note || item.note;
      if (itemNote) {
        lines.push(`      * NOTA: ${itemNote}`);
      }
      
      let allergens = item.allergen_flags;
      if (typeof allergens === 'string') {
        try { allergens = JSON.parse(allergens); } catch { allergens = allergens.split(','); }
      }
      if (allergens && Array.isArray(allergens) && allergens.length > 0) {
        const allergenNames = allergens.map(f => allergenTextMap[f] || f).join(', ');
        lines.push(`      * ALERGIA: ${allergenNames}`);
      }
    }
  }
  
  lines.push(sepDa);
  
  if (sale.notes) {
    lines.push("OBSERVACIONES GENERALES:");
    const noteStr = `- ${sale.notes}`;
    for (let i = 0; i < noteStr.length; i += width) {
      lines.push(noteStr.substring(i, i + width));
    }
    lines.push(sepDa);
  }
  
  const totalAmt = sale.total_amount ? Number(sale.total_amount).toFixed(2) : '0.00';
  const totalStr = `TOTAL ITEMS: ${padR(String(totalItems), 16)}TOTAL BOB: ${padL(totalAmt, 8)}`;
  lines.push(totalStr);

  if (takeawayCount > 0 && takeawayCount < totalCount) {
    const dineInCount = totalCount - takeawayCount;
    lines.push(`DESGLOSE:    ${padR(`${dineInCount}x MESA · ${takeawayCount}x LLEVAR`, 33)}`);
  }
  
  lines.push(sepEq);
  lines.push(padC("[Corte de Papel]", width));
  
  return lines.join('\n');
});

const printTicket = () => {
  window.print();
};
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="modal-overlay active" @click.self="emit('close')">
      <div class="modal-card ticket-modal">
        <div class="modal-head no-print">
          <h2>Comanda de Cocina (EPSON TM-T20)</h2>
          <button type="button" class="modal-close" title="Cerrar" @click.stop="emit('close')">✕</button>
        </div>

        <div class="modal-body">
          <pre class="ascii-ticket printable-area">{{ ticketText }}</pre>
        </div>

        <div class="modal-foot no-print">
          <button type="button" class="btn btn-ghost" @click.stop="emit('close')">
            {{ hasNextAction ? '↩️ Volver / Editar' : 'Cerrar' }}
          </button>
          <div style="display:flex; gap:8px;">
            <button type="button" class="btn btn-ghost" @click="printTicket">🖨️ Imprimir comanda</button>
            <button v-if="hasNextAction" type="button" class="btn btn-primary" @click="emit('next')">
              Continuar ➔
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* Print Styles */
@media print {
  body * {
    visibility: hidden;
  }
  .printable-area, .printable-area * {
    visibility: visible;
  }
  .printable-area {
    position: absolute;
    left: 0;
    top: 0;
    margin: 0;
    padding: 0;
  }
  .no-print {
    display: none !important;
  }
  .modal-overlay {
    background: transparent !important;
  }
  .ticket-modal {
    box-shadow: none !important;
    border: none !important;
  }
}

/* Screen Styles */
.modal-overlay {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99999 !important;
  background: rgba(18, 10, 26, 0.65) !important;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 16px;
  animation: fadeIn 0.15s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.ticket-modal {
  max-width: 500px;
  width: 100%;
  background: var(--surface, #ffffff);
  border-radius: 16px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
  overflow: hidden;
  animation: popIn 0.2s cubic-bezier(0.2, 0.8, 0.2, 1);
}

@keyframes popIn {
  from { transform: scale(0.96); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.modal-head {
  padding: 18px 22px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--border, #e2e8f0);
  background: var(--cream-50, #FAF8F5);
}

.modal-head h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 800;
  color: var(--ink-900, #1e293b);
}

.modal-close {
  background: none;
  border: none;
  font-size: 20px;
  font-weight: 700;
  cursor: pointer;
  color: var(--ink-500, #64748b);
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}

.modal-close:hover {
  background: var(--cream-200, #e2e8f0);
  color: var(--ink-900, #000);
}

.modal-body {
  padding: 18px 20px;
  background: var(--surface, #ffffff);
  max-height: 72vh;
  overflow-y: auto;
}

.ascii-ticket {
  font-family: 'Courier New', Courier, monospace;
  font-size: 13px;
  line-height: 1.25;
  color: #000;
  background: #fff;
  padding: 16px 18px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  overflow-x: auto;
  white-space: pre;
  margin: 0 auto;
  width: max-content;
  box-shadow: inset 0 1px 3px rgba(0,0,0,0.06);
}

:global(html.dark) .ascii-ticket {
  background: #111;
  color: #0f0;
  border-color: #333;
}

.modal-foot {
  padding: 14px 20px;
  border-top: 1px solid var(--border, #e2e8f0);
  background: var(--cream-50, #FAF8F5);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.btn {
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
  padding: 9px 16px;
  border-radius: 9px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-ghost {
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #cbd5e1);
  color: var(--ink-700, #334155);
}

.btn-ghost:hover {
  background: var(--cream-200, #e2e8f0);
}

.btn-primary {
  background: var(--passion-600, #ea580c);
  color: #fff;
  border: none;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.btn-primary:hover {
  background: var(--passion-700, #c2410c);
}

@media (max-width: 1366px), (max-height: 800px) {
  .ticket-modal {
    max-width: 450px;
    max-height: 94vh;
  }
  .ascii-ticket {
    font-size: 11.5px;
    padding: 12px 14px;
  }
}
</style>
