<script setup>
import { computed } from 'vue';
import { useAuthStore } from '../stores/auth';

const props = defineProps({
  show: Boolean,
  sale: Object
});

const emit = defineEmits(['close', 'next']);

const auth = useAuthStore();

const allergenTextMap = {
  'lactose': 'Lactosa',
  'gluten': 'Gluten',
  'almond': 'Almendras',
  'fruit': 'Fruta',
  'egg': 'Huevo'
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
  
  const idShort = sale.id ? sale.id.split('-')[0].toUpperCase() : '0000';
  const tipoStr = sale.source === 'pos' ? 'MOSTRADOR' : 'DELIVERY';
  lines.push(`Comanda Nro: #${padR(idShort, 15)}Tipo: ${tipoStr}`);
  
  const userName = (auth.user && auth.user.name) ? String(auth.user.name) : 'Cajero';
  lines.push(`Mesa: ${padR('N/A', 23)}Atiende: ${userName.substring(0, 9)}`);
  
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
      totalItems += item.quantity;
      let baseName = (item.name || 'Item').toUpperCase();
      let sizeMod = null;
      let otherMods = [];

      if (item.modifiers && item.modifiers.length > 0) {
        for (const mod of item.modifiers) {
          if (mod.group_name && mod.group_name.toLowerCase().includes('tamaño')) {
            sizeMod = mod;
          } else {
            otherMods.push(mod);
          }
        }
      }

      if (item.is_takeaway) {
         baseName += ` (PARA LLEVAR)`;
      }

      if (sizeMod) {
         baseName += `   * ${sizeMod.option_name}`;
      }

      const qtyStr = padC(`${item.quantity}x`, 4);
      const nameStr = padR(baseName, 33);
      
      let basePrice = Number(item.base_price);
      if (isNaN(basePrice) || (basePrice === 0 && item.subtotal > 0)) {
        let modSum = 0;
        if (item.modifiers) {
          item.modifiers.forEach(m => modSum += (Number(m.price) || 0));
        }
        const unitPrice = Number(item.unit_price) || (Number(item.subtotal) / item.quantity);
        basePrice = unitPrice - modSum;
      }
      
      let mainLineBasePrice = basePrice;
      if (sizeMod && sizeMod.price) {
        mainLineBasePrice += Number(sizeMod.price);
      }
      
      const mainLineTotal = mainLineBasePrice * item.quantity;
      const mainSubtotalStr = padL(mainLineTotal.toFixed(2), 9);
      lines.push(`${qtyStr} ${nameStr}${mainSubtotalStr}`);
      
      if (otherMods.length > 0) {
        for (const mod of otherMods) {
           const modNameStr = padR(`      * ${mod.option_name}`, 38);
           const mPrice = Number(mod.price) || 0;
           const modTotal = mPrice * item.quantity;
           const modPriceStr = padL(modTotal.toFixed(2), 9);
           lines.push(`${modNameStr}${modPriceStr}`);
        }
      }
      
      if (item.item_note) {
         lines.push(`      * ${item.item_note}`);
      }
      
      if (item.allergen_flags && item.allergen_flags.length > 0) {
         const allergens = item.allergen_flags.map(f => allergenTextMap[f] || f).join(', ');
         lines.push(`      * ALERGIA: ${allergens}`);
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
  
  lines.push(sepEq);
  lines.push(padC("[Corte de Papel]", width));
  
  return lines.join('\n');
});

const printTicket = () => {
  window.print();
};
</script>

<template>
  <div class="modal-overlay" :class="{ active: show }">
    <div class="modal-card ticket-modal">
      <div class="modal-head no-print">
        <h2>Comanda de Cocina (EPSON TM-T20)</h2>
        <button class="modal-close" @click="emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <pre class="ascii-ticket printable-area">{{ ticketText }}</pre>
      </div>

      <div class="modal-foot no-print">
        <button class="btn btn-ghost" @click="printTicket">🖨️ Imprimir comanda</button>
        <button class="btn btn-primary" @click="emit('next')">
          Continuar
        </button>
      </div>
    </div>
  </div>
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
    background: transparent;
  }
  .ticket-modal {
    box-shadow: none;
    border: none;
  }
}

/* Screen Styles */
.ticket-modal {
  max-width: 500px;
  width: 100%;
}

.ascii-ticket {
  font-family: 'Courier New', Courier, monospace;
  font-size: 14px;
  line-height: 1.2;
  color: #000;
  background: #fff;
  padding: 20px;
  border-radius: 4px;
  border: 1px solid #ccc;
  overflow-x: auto;
  white-space: pre;
  margin: 0 auto;
  width: max-content;
}

:global(html.dark) .ascii-ticket {
  background: #111;
  color: #0f0; /* Terminal look in dark mode for fun */
  border-color: #333;
}

@media print {
  .ascii-ticket {
    border: none;
    color: #000 !important;
    background: #fff !important;
    font-size: 12px; /* Adjust as needed for thermal printer */
  }
}
</style>
