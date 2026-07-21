import { db } from '../db/database';
import { apiFetch } from './api';

export async function syncPendingSales() {
  // Buscar todas las ventas con sync_status = pending
  const pendingSales = await db.sales.where('sync_status').equals('pending').toArray();
  
  if (pendingSales.length === 0) return;

  try {
    // El backend espera el formato { sales: [...] }
    const response = await apiFetch('/sales/sync', {
      method: 'POST',
      body: JSON.stringify({ sales: pendingSales })
    });

    // Marcar como sincronizadas en IndexedDB
    // El backend devuelve los UUIDs procesados en response.data.synced
    if (response && response.data && response.data.synced) {
      const syncedIds = response.data.synced;
      
      await db.transaction('rw', db.sales, async () => {
        for (const id of syncedIds) {
          await db.sales.update(id, { sync_status: 'synced' });
        }
      });
      
      // Actualizamos el catálogo para asegurar sincronía de stock real
      try {
        const { useCatalogStore } = await import('../stores/catalog.js');
        await useCatalogStore().fetchAndCache();
      } catch(e) {
        console.warn("No se pudo actualizar catálogo post-sync", e);
      }
    }

    // Aquí podríamos manejar los fallos parciales (response.data.failed) si quisiéramos

    // Ejecutar la rutina de limpieza (pruning)
    await pruneOldSyncedSales();

  } catch (error) {
    console.error("Fallo al sincronizar ventas con el servidor. Se reintentará luego.", error);
    throw error;
  }
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
