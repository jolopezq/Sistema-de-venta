import test from 'node:test';
import assert from 'node:assert/strict';
import { classifyArchiveFiles } from './pedidosYaArchive.js';

test('classifyArchiveFiles agrupa archivos de las 5 semanas y detecta CSV e ignorados', () => {
  const mockFiles = [
    { name: '.DS_Store' },
    { name: 'desktop.ini' },
    { name: 'notas.txt' },
    { name: 'orderDetails.csv' },
    // Semana 1: 7800702619
    { name: 'account_statement_7800702619_1.pdf' },
    { name: 'invoice-7800702619.xls' },
    { name: 'invoice_7800702619.pdf' },
    // Semana 2: 7800706345
    { name: 'account_statement_7800706345_1.pdf' },
    { name: 'invoice-7800706345.xls' },
    { name: 'invoice_7800706345.pdf' },
    // Semana 3: 7800712391 (solo PDF y XLS)
    { name: 'account_statement_7800712391_1.pdf' },
    { name: 'invoice-7800712391.xls' },
    // Archivo suelto sin estado de cuenta
    { name: 'invoice_9999999999.pdf' },
  ];

  const result = classifyArchiveFiles(mockFiles);

  assert.equal(result.validWeeks.length, 3);
  assert.equal(result.csvFile?.name, 'orderDetails.csv');
  assert.equal(result.ignoredFiles.length, 2); // notas.txt e invoice_9999999999.pdf
  assert.equal(result.ignoredFiles[0].name, 'notas.txt');
  assert.equal(result.ignoredFiles[1].name, 'invoice_9999999999.pdf');

  const w1 = result.validWeeks.find((w) => w.id === '7800702619');
  assert.ok(w1);
  assert.equal(w1.statement_file.name, 'account_statement_7800702619_1.pdf');
  assert.equal(w1.orders_xls.name, 'invoice-7800702619.xls');
  assert.equal(w1.invoice_pdf.name, 'invoice_7800702619.pdf');

  const w3 = result.validWeeks.find((w) => w.id === '7800712391');
  assert.ok(w3);
  assert.equal(w3.statement_file.name, 'account_statement_7800712391_1.pdf');
  assert.equal(w3.orders_xls.name, 'invoice-7800712391.xls');
  assert.equal(w3.invoice_pdf, null);
});
