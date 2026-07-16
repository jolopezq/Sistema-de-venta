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
    }

    // Aquí podríamos manejar los fallos parciales (response.data.failed) si quisiéramos

  } catch (error) {
    console.error("Fallo al sincronizar ventas con el servidor. Se reintentará luego.", error);
    throw error;
  }
}
