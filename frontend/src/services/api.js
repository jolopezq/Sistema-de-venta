export const API_URL = import.meta.env.VITE_API_URL || '/api';

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
