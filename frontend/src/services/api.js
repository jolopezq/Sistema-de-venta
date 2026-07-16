export const API_URL = 'http://localhost:8000/api'; // En prod usar import.meta.env.VITE_API_URL

export async function apiFetch(endpoint, options = {}) {
  const token = localStorage.getItem('auth_token');
  
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...options.headers,
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const response = await fetch(`${API_URL}${endpoint}`, {
    ...options,
    headers,
  });

  // Si el token expira o es inválido, forzar logout
  if (response.status === 401) {
    localStorage.removeItem('auth_token');
    // Para no importar router directamente y causar dependencias circulares, usamos window
    if (window.location.pathname !== '/login') {
      window.location.href = '/login';
    }
  }

  if (!response.ok) {
    let errorMessage = 'Error en la petición';
    try {
      const errData = await response.json();
      errorMessage = errData.message || errorMessage;
    } catch (e) {
      // Ignorar si no es JSON
    }
    throw new Error(errorMessage);
  }

  if (response.status === 204) {
    return null;
  }

  return response.json();
}
