export const API_URL = (() => {
  const envUrl = import.meta.env.VITE_API_URL;
  if (envUrl && envUrl.startsWith('http') && typeof window !== 'undefined') {
    const isLocalhostEnv = envUrl.includes('127.0.0.1') || envUrl.includes('localhost');
    const isLocalhostWindow = window.location.hostname === '127.0.0.1' || window.location.hostname === 'localhost';
    if (isLocalhostEnv && !isLocalhostWindow) {
      return '/api';
    }
    return envUrl;
  }
  return envUrl || '/api';
})();

export async function apiFetch(endpoint, options = {}) {
  const token = localStorage.getItem('auth_token');
  
  const headers = {
    'Accept': 'application/json',
    ...options.headers,
  };
  
  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_URL}${endpoint}`, {
    ...options,
    headers,
  });

  // Si el token expira o es inválido, forzar logout (excepto si la petición es al endpoint de /login)
  if (response.status === 401 && endpoint !== '/login') {
    localStorage.removeItem('auth_token');
    try {
      const { useAuthStore } = await import('../stores/auth.js');
      const auth = useAuthStore();
      auth.token = null;
      auth.user = null;
    } catch (_) {}

    if (window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
  }

  if (!response.ok) {
    let errorMessage = 'Error en la petición';
    let errorObj = new Error(errorMessage);
    errorObj.status = response.status;
    
    try {
      const errData = await response.json();
      errorMessage = errData.message || errorMessage;
      errorObj.message = errorMessage;
      
      if (errData.errors) {
        errorObj.validationErrors = errData.errors;
        const messages = Object.values(errData.errors).flat();
        if (messages.length > 0) {
          errorObj.message = errorMessage + ':\n- ' + messages.join('\n- ');
        }
      }
    } catch (e) {
      // Ignorar si no es JSON
    }
    throw errorObj;
  }

  if (response.status === 204) {
    return null;
  }

  return response.json();
}

/**
 * Realiza una petición autenticada para descargar un archivo binario (ej: backup Gzip/CSV).
 */
export async function apiDownload(endpoint, bodyData = {}, defaultFilename = 'download') {
  const token = localStorage.getItem('auth_token');
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/gzip, application/octet-stream, application/json',
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_URL}${endpoint}`, {
    method: 'POST',
    headers,
    body: JSON.stringify(bodyData),
  });

  if (!response.ok) {
    let errorMessage = 'Error al descargar archivo';
    try {
      const errJson = await response.json();
      errorMessage = errJson.message || errorMessage;
    } catch (_) {}
    const err = new Error(errorMessage);
    err.status = response.status;
    throw err;
  }

  // Extraer nombre del archivo si viene en content-disposition
  let filename = defaultFilename;
  const disposition = response.headers.get('content-disposition');
  if (disposition && disposition.includes('filename=')) {
    const match = disposition.match(/filename="?([^"]+)"?/);
    if (match && match[1]) {
      filename = match[1];
    }
  }

  const blob = await response.blob();
  const downloadUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = downloadUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  window.URL.revokeObjectURL(downloadUrl);
  a.remove();

  return { filename, size: blob.size };
}

