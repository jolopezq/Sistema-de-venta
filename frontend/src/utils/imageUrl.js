/**
 * Helper centralizado para resolver URLs de imágenes de productos y recursos estáticos.
 * 
 * Funciona de manera transparente tanto en:
 * 1. Desarrollo local (localhost:5173 vía proxy de Vite a Laravel :8000)
 * 2. Red local Wi-Fi (ej. 192.168.1.14:5173)
 * 3. Túneles públicos HTTPS (Cloudflare Tunnel, Ngrok) sin errores de Mixed-Content
 * 4. Producción (con o sin CDN / VITE_STORAGE_URL personalizado)
 */
export function resolveImageUrl(url) {
  if (!url) return null;
  
  // Si ya es una URL absoluta o base64, respetarla
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
    return url;
  }

  const storageBase = import.meta.env.VITE_STORAGE_URL || '/storage';
  const cleanPath = url.startsWith('/') ? url : '/' + url;
  
  if (cleanPath.startsWith('/storage/')) {
    return cleanPath;
  }

  return `${storageBase}${cleanPath}`;
}
