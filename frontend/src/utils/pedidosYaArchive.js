/**
 * Clasifica y agrupa los archivos de un paquete/carpeta semanal o mensual de PedidosYa.
 * Soporta carpetas de liquidación (payoutArchive_*) y archivos individuales.
 */
export function classifyArchiveFiles(fileList) {
  const groups = {}; // id -> { id, statement_file, orders_xls, invoice_pdf }
  let csvFile = null;
  const ignoredFiles = [];

  const files = Array.isArray(fileList) ? fileList : Array.from(fileList || []);

  for (const file of files) {
    const name = file.name || '';

    // Ignorar archivos ocultos del sistema (.DS_Store, thumbs.db)
    if (name.startsWith('.') || name.toLowerCase() === 'thumbs.db' || name.toLowerCase() === 'desktop.ini') {
      continue;
    }

    // 1. Archivo CSV de pedidos
    if (name.toLowerCase().endsWith('.csv')) {
      if (!csvFile || name.toLowerCase().includes('order')) {
        csvFile = file;
      }
      continue;
    }

    // 2. Estado de cuenta en PDF: account_statement_<ID>_*.pdf
    const stMatch = name.match(/account_statement_(\d+)/i);
    if (stMatch && name.toLowerCase().endsWith('.pdf')) {
      const id = stMatch[1];
      if (!groups[id]) groups[id] = { id, statement_file: null, orders_xls: null, invoice_pdf: null };
      groups[id].statement_file = file;
      continue;
    }

    // 3. Planilla de pedidos en Excel: invoice-<ID>.xls
    const xlsMatch = name.match(/invoice-(\d+)\.xls/i);
    if (xlsMatch) {
      const id = xlsMatch[1];
      if (!groups[id]) groups[id] = { id, statement_file: null, orders_xls: null, invoice_pdf: null };
      groups[id].orders_xls = file;
      continue;
    }

    // 4. Factura fiscal en PDF: invoice_<ID>.pdf
    const invMatch = name.match(/invoice_(\d+)\.pdf/i);
    if (invMatch) {
      const id = invMatch[1];
      if (!groups[id]) groups[id] = { id, statement_file: null, orders_xls: null, invoice_pdf: null };
      groups[id].invoice_pdf = file;
      continue;
    }

    // Otros archivos se registran como ignorados
    ignoredFiles.push(file);
  }

  // Filtrar semanas válidas (aquellas que al menos tienen el estado de cuenta)
  const validWeeks = [];
  for (const group of Object.values(groups)) {
    if (group.statement_file) {
      validWeeks.push(group);
    } else {
      // Si hay archivos huérfanos sin estado de cuenta, se marcan como ignorados
      if (group.orders_xls) ignoredFiles.push(group.orders_xls);
      if (group.invoice_pdf) ignoredFiles.push(group.invoice_pdf);
    }
  }

  validWeeks.sort((a, b) => a.id.localeCompare(b.id));

  return {
    validWeeks,
    csvFile,
    ignoredFiles,
  };
}
