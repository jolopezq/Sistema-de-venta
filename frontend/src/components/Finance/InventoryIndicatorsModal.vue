<script setup>
import { computed } from 'vue';
import FinanceModal from './FinanceModal.vue';
import { useFinanceStore, MONTHS } from '../../stores/finance';
import { indicatorRows, reportSections, rowBadge } from '../../utils/inventoryIndicators';

/** Informe mensual de inventario: los seis indicadores con su meta y el detalle que los explica, listo para imprimir. */
const emit = defineEmits(['close']);
const finance = useFinanceStore();

const data = computed(() => finance.inventoryIndicators);
const rows = computed(() => indicatorRows(data.value));
const sections = computed(() => reportSections(data.value));
const periodText = computed(() => (data.value ? `${MONTHS[data.value.period.month - 1]} ${data.value.period.year}` : finance.periodLabel));

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Imprime desde un marco oculto: no depende de ventanas emergentes ni de los estilos de la pantalla. */
function print() {
  const today = new Date().toLocaleDateString('es-BO', { day: '2-digit', month: 'long', year: 'numeric' });
  const summary = rows.value.map((r) => `<tr><td>${escapeHtml(r.label)}</td><td class="n">${escapeHtml(r.value)}</td><td>${escapeHtml(r.goal)}</td><td>${escapeHtml(rowBadge(r).label)}</td><td>${escapeHtml(r.previous || r.detail)}</td></tr>`).join('');
  const detail = sections.value.map((s) => {
    const body = s.rows.length
      ? `<table><thead><tr>${s.head.map((h, i) => `<th${i ? ' class="n"' : ''}>${escapeHtml(h)}</th>`).join('')}</tr></thead><tbody>${s.rows.map((r) => `<tr>${r.map((c, i) => `<td${i ? ' class="n"' : ''}>${escapeHtml(c)}</td>`).join('')}</tr>`).join('')}</tbody></table>`
      : `<p class="m">${escapeHtml(s.empty)}</p>`;
    return `<h2>${escapeHtml(s.title)}</h2>${body}${s.note ? `<p class="m">${escapeHtml(s.note)}</p>` : ''}`;
  }).join('');
  const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Informe de inventario ${escapeHtml(periodText.value)}</title><style>
    body{font:13px/1.45 system-ui,sans-serif;color:#111;margin:24px}h1{font-size:19px;margin:0 0 2px}.s{color:#555;margin:0 0 16px}
    h2{font-size:14px;margin:20px 0 4px;break-after:avoid}.m{margin:4px 0 0;color:#555;font-size:12px}table{width:100%;border-collapse:collapse;break-inside:auto}
    th{text-align:left;font-size:11.5px;color:#444;border-bottom:2px solid #999;padding:4px 6px}td{padding:5px 6px;border-bottom:1px solid #ccc;vertical-align:top}
    .n{text-align:right;white-space:nowrap}th.n{text-align:right}tr{break-inside:avoid}.sum td:first-child{font-weight:600}
  </style></head><body><h1>Informe de inventario — Ohana</h1><p class="s">${escapeHtml(periodText.value)} · impreso el ${escapeHtml(today)}</p>
    <h2>Indicadores</h2><table class="sum"><thead><tr><th>Indicador</th><th class="n">Valor</th><th>Meta</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>${summary}</tbody></table>
    ${detail}
    <p class="m" style="margin-top:18px">Metas iniciales. El valor del inventario y la cobertura son de hoy; los demás indicadores corresponden al mes.</p>
  </body></html>`;

  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
  document.body.appendChild(frame);
  frame.contentDocument.open();
  frame.contentDocument.write(html);
  frame.contentDocument.close();
  setTimeout(() => {
    frame.contentWindow.focus();
    frame.contentWindow.print();
    setTimeout(() => document.body.removeChild(frame), 2000);
  }, 150);
}
</script>

<template>
  <FinanceModal wide :title="`Informe de inventario · ${periodText}`" subtitle="Los indicadores del mes con su meta y el detalle que los explica." @close="emit('close')">
    <div class="fin-table-wrap">
      <table class="fin-table stack">
        <thead><tr><th>Indicador</th><th class="right">Valor</th><th>Meta</th><th>Estado</th><th>Detalle</th></tr></thead>
        <tbody>
          <tr v-for="r in rows" :key="r.key">
            <td data-label="Indicador"><span class="fin-cell-main">{{ r.label }}</span></td>
            <td class="right fin-num" data-label="Valor"><strong>{{ r.value }}</strong></td>
            <td data-label="Meta"><span class="fin-small">{{ r.goal }}</span></td>
            <td data-label="Estado"><span class="fin-badge" :class="rowBadge(r).tone">{{ rowBadge(r).label }}</span></td>
            <td data-label="Detalle">
              <span class="fin-small">{{ r.detail }}</span>
              <div v-if="r.previous" class="fin-small fin-muted">{{ r.previous }}</div>
              <div v-if="r.hint && r.status !== 'ok'" class="fin-small fin-muted">{{ r.hint }}</div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <section v-for="s in sections" :key="s.key" class="iim-section">
      <h3>{{ s.title }}</h3>
      <div v-if="s.rows.length" class="fin-table-wrap">
        <table class="fin-table stack">
          <thead><tr><th v-for="(h, i) in s.head" :key="h" :class="{ right: i > 0 }">{{ h }}</th></tr></thead>
          <tbody>
            <tr v-for="(r, ri) in s.rows" :key="ri">
              <td v-for="(c, ci) in r" :key="ci" :class="{ right: ci > 0, 'fin-num': ci > 0 }" :data-label="s.head[ci]">{{ c }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="fin-small fin-muted iim-empty">{{ s.empty }}</p>
      <p v-if="s.note" class="fin-small fin-muted iim-note">{{ s.note }}</p>
    </section>

    <template #footer>
      <span class="fin-small fin-muted">Metas iniciales. El valor y la cobertura son de hoy; lo demás, del mes.</span>
      <span class="fin-spacer"></span>
      <button class="fin-btn fin-btn-secondary" type="button" @click="print">Imprimir</button>
      <button class="fin-btn fin-btn-primary" type="button" @click="emit('close')">Cerrar</button>
    </template>
  </FinanceModal>
</template>

<style scoped>
.iim-section { margin-top: 18px; }
.iim-section h3 { margin: 0 0 6px; font-size: 15px; color: var(--ink-900); }
.iim-empty, .iim-note { margin: 4px 0 0; }
</style>
