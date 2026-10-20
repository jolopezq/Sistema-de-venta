/**
 * Reglas centralizadas de pantallas por rol y permisos para Ohana POS.
 *
 * Utilizado por:
 * - AppShell.vue (para renderizar el menú lateral real de la app)
 * - router/index.js (para calcular la pantalla de inicio landingFor)
 * - RolePreview.vue (para renderizar la maqueta previa en la pantalla de permisos)
 */

/**
 * Devuelve las pantallas visibles, ocultas y la pantalla de inicio (landing)
 * para un rol dado y una función u objeto de permisos { [module]: level }.
 *
 * @param {string} role 'kitchen' | 'cashier' | 'admin' | 'super_admin'
 * @param {Function|Object} levelOf (module: string) => string ('none'|'read'|'edit') o mapa clave-valor
 * @returns {{
 *   screens: Array<{ id: string, name: string, route: string, section: string, icon: string, isReadOnly: boolean, isLanding: boolean }>,
 *   hiddenScreens: Array<{ id: string, name: string }>,
 *   landing: string,
 *   hasAccess: boolean
 * }}
 */
export function screensFor(role, levelOf) {
  const getLevel = typeof levelOf === 'function'
    ? levelOf
    : (mod) => levelOf?.[mod] ?? 'none';

  const isSuperAdmin = role === 'super_admin';
  const isAdmin = role === 'admin' || isSuperAdmin;
  const isKitchen = role === 'kitchen';

  // Catálogo maestro de pantallas operativas del sistema
  const allScreens = [
    {
      id: 'pos',
      name: 'Vender',
      route: '/pos',
      section: 'Operación',
      icon: 'cart',
      isVisible: isSuperAdmin || (!isKitchen && getLevel('pos') !== 'none'),
      isReadOnly: !isSuperAdmin && getLevel('pos') === 'read',
    },
    {
      id: 'register_self',
      name: 'Caja',
      route: '/turno',
      section: 'Operación',
      icon: 'cash',
      isVisible: isSuperAdmin || (!isKitchen && getLevel('register_self') !== 'none'),
      isReadOnly: !isSuperAdmin && getLevel('register_self') === 'read',
    },
    {
      id: 'delivery_ops',
      name: 'Delivery',
      route: '/delivery',
      section: 'Operación',
      icon: 'delivery',
      isVisible: isSuperAdmin || getLevel('delivery_ops') !== 'none',
      isReadOnly: !isSuperAdmin && getLevel('delivery_ops') === 'read',
    },
    {
      id: 'catalog',
      name: 'Menú',
      route: '/menu',
      section: 'Operación',
      icon: 'catalog',
      isVisible: isSuperAdmin || getLevel('catalog') !== 'none',
      isReadOnly: !isSuperAdmin && getLevel('catalog') === 'read',
    },
    {
      id: 'inventory',
      name: 'Inventario',
      route: '/inventario',
      section: 'Operación',
      icon: 'inventory',
      isVisible: isSuperAdmin || (!isKitchen && getLevel('inventory') === 'edit'),
      isReadOnly: false,
    },
    {
      id: 'retroactive',
      name: 'Control diario',
      route: '/admin/ventas-retroactivas',
      section: 'Sistema',
      icon: 'retroactive',
      isVisible: isAdmin && (isSuperAdmin || getLevel('pos') === 'edit'),
      isReadOnly: false,
    },
  ];

  // Pantallas exclusivas de Super Admin en Sistema
  const superAdminScreens = [
    { id: 'finance', name: 'Finanzas', route: '/finanzas', section: 'Sistema', icon: 'finance', isVisible: isSuperAdmin, isReadOnly: false },
    { id: 'users', name: 'Usuarios', route: '/users', section: 'Sistema', icon: 'users', isVisible: isSuperAdmin, isReadOnly: false },
    { id: 'audit', name: 'Auditoría', route: '/audit-logs', section: 'Sistema', icon: 'audit', isVisible: isSuperAdmin, isReadOnly: false },
  ];

  const candidateScreens = [...allScreens, ...superAdminScreens];
  const visible = candidateScreens.filter(s => s.isVisible);

  // Determinar pantalla de inicio (Landing)
  let landing = '/sin-acceso';

  if (isSuperAdmin) {
    landing = '/pos';
  } else if (isKitchen) {
    landing = getLevel('delivery_ops') !== 'none' ? '/delivery' : '/sin-acceso';
  } else {
    // Para cajero y admin: prioridad según flujo operativo
    if (getLevel('pos') !== 'none') {
      landing = '/pos';
    } else if (getLevel('register_self') !== 'none') {
      landing = '/turno';
    } else if (getLevel('delivery_ops') !== 'none') {
      landing = '/delivery';
    } else if (getLevel('catalog') !== 'none') {
      landing = '/menu';
    } else if (getLevel('inventory') === 'edit') {
      landing = '/inventario';
    } else if (isAdmin && getLevel('pos') === 'edit') {
      landing = '/admin/ventas-retroactivas';
    } else {
      landing = '/sin-acceso';
    }
  }

  // Marcar cuál es la pantalla que abre
  const screens = visible.map(s => ({
    ...s,
    isLanding: s.route === landing,
  }));

  // Lista de pantallas operativas ocultas para este rol
  const hiddenScreens = allScreens
    .filter(s => !s.isVisible)
    .map(s => ({ id: s.id, name: s.name }));

  const hasAccess = landing !== '/sin-acceso' && screens.length > 0;

  return {
    screens,
    hiddenScreens,
    landing,
    hasAccess,
  };
}
