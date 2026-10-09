import { db } from '../db/database';
import { apiFetch } from './api';

/**
 * Sincronización de ventas guardadas en el dispositivo (offline-first).
 *
 * Estados de una venta local (`sync_status`):
 *  - pending: falta enviarla; se reintenta sola cada 30 s o al volver la conexión.
 *  - failed:  el servidor la rechazó MAX_SYNC_ATTEMPTS veces seguidas (con el motivo en `sync_error`);
 *             deja de reintentarse sola y se muestra en Turno para revisarla. Su cobro aún no está en
 *             ninguna caja, por eso bloquea el cierre.
 *  - synced:  ya está en el servidor.
 *
 * Un error de un solo intento puede ser pasajero (base ocupada, por ejemplo), por eso no se marca
 * como rechazada a la primera.
 */
export const MAX_SYNC_ATTEMPTS = 3;

export async function syncPendingSales() {
  const pendingSales = await db.sales.where('sync_status').equals('pending').toArray();

  if (pendingSales.length === 0) return;

  let results;
  try {
    results = await sendBatch(pendingSales);
  } catch (error) {
    if (error?.status !== 422) {
      // Sin conexión o error del servidor: no es culpa de una venta; se reintenta luego
      console.error('Fallo al sincronizar ventas con el servidor. Se reintentará luego.', error);
      throw error;
    }
    // Una venta que no pasa la validación frena todo el lote: se envían de a una para aislarla
    results = await sendOneByOne(pendingSales);
  }

  await markResults(pendingSales, results);

  if (results.synced.length > 0) {
    // Actualizamos el catálogo para asegurar sincronía de stock real
    try {
      const { useCatalogStore } = await import('../stores/catalog.js');
      await useCatalogStore().fetchAndCache();
    } catch (e) {
      console.warn('No se pudo actualizar catálogo post-sync', e);
    }
  }

  await pruneOldSyncedSales();
}

async function sendBatch(sales) {
  // El backend espera { sales: [...] } y responde { data: { synced: [ids], failed: [{ id, error }] } }
  const response = await apiFetch('/sales/sync', {
    method: 'POST',
    body: JSON.stringify({ sales }),
  });
  return {
    synced: response?.data?.synced || [],
    failed: response?.data?.failed || [],
  };
}

async function sendOneByOne(sales) {
  const results = { synced: [], failed: [] };
  for (const sale of sales) {
    try {
      const one = await sendBatch([sale]);
      results.synced.push(...one.synced);
      results.failed.push(...one.failed);
    } catch (error) {
      if (error?.status === 422) {
        results.failed.push({ id: sale.id, error: validationText(error) });
        continue;
      }
      // Se cortó la conexión a mitad: se guarda lo que ya se resolvió y se reintenta el resto luego
      await markResults(sales, results);
      throw error;
    }
  }
  return results;
}

/** Guarda el resultado del envío en cada venta local (los ids repetidos se ignoran). */
async function markResults(sales, { synced, failed }) {
  const byId = new Map(sales.map((s) => [s.id, s]));
  const now = new Date().toISOString();

  await db.transaction('rw', db.sales, async () => {
    for (const id of new Set(synced)) {
      await db.sales.update(id, { sync_status: 'synced', sync_error: null, sync_attempts: 0 });
    }
    for (const item of failed) {
      const sale = byId.get(item.id);
      if (!sale || synced.includes(item.id)) continue;
      const attempts = (Number(sale.sync_attempts) || 0) + 1;
      await db.sales.update(item.id, {
        sync_status: attempts >= MAX_SYNC_ATTEMPTS ? 'failed' : 'pending',
        sync_attempts: attempts,
        sync_error: item.error || 'El servidor no aceptó la venta.',
        sync_failed_at: now,
      });
    }
  });
}

function validationText(error) {
  const messages = error?.validationErrors ? Object.values(error.validationErrors).flat() : [];
  return messages.length ? messages.join(' ') : (error?.message || 'El servidor no aceptó la venta.');
}

/** Ventas que el servidor rechazó, de la más reciente a la más antigua. */
export async function listFailedSales() {
  const sales = await db.sales.where('sync_status').equals('failed').toArray();
  return sales.sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
}

/** Vuelve a poner en la cola las ventas rechazadas (todas o las indicadas) con los intentos en cero. */
export async function retryFailedSales(ids = null) {
  const failed = await db.sales.where('sync_status').equals('failed').toArray();
  const targets = ids ? failed.filter((s) => ids.includes(s.id)) : failed;
  await db.transaction('rw', db.sales, async () => {
    for (const sale of targets) {
      await db.sales.update(sale.id, { sync_status: 'pending', sync_attempts: 0 });
    }
  });
  return targets.length;
}

/**
 * Rutina de limpieza (Pruning) que elimina de IndexedDB
 * las ventas con estatus 'synced' que tengan más de 7 días.
 * (buenas-practicas.md §3)
 */
export async function pruneOldSyncedSales() {
  try {
    const sevenDaysAgo = new Date();
    sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);
    const dateLimit = sevenDaysAgo.toISOString();

    const oldSales = await db.sales
      .where('sync_status')
      .equals('synced')
      .and(sale => sale.created_at < dateLimit)
      .primaryKeys();

    if (oldSales.length > 0) {
      await db.sales.bulkDelete(oldSales);
      console.log(`Pruning exitoso: se eliminaron ${oldSales.length} ventas antiguas sincronizadas.`);
    }
  } catch (error) {
    console.error("Error ejecutando limpieza de ventas antiguas:", error);
  }
}
