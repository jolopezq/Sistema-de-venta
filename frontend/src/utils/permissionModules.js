/**
 * Catálogo de módulos, descripciones, etiquetas de niveles y frases resumen
 * según especificación en docs/PLAN_PANTALLA_PERMISOS.md §2.1 y §2.2.
 */

export const PERMISSION_MODULES = [
  {
    id: 'pos',
    name: 'Ventas',
    desc: 'Cobrar en el punto de venta y revisar las ventas del día.',
    adminDesc: 'Cobrar, revisar las ventas del día y cargarlas en Control diario.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Ver ventas',
      edit: 'Vender',
    },
    verbs: {
      read: 've las ventas sin poder cobrar',
      edit: 'vende',
    },
    adminExceptions: {
      none: 'vender',
      read: 'cobrar',
    },
    fixedLimit: 'vender',
  },
  {
    id: 'register_self',
    name: 'Su propia caja',
    desc: 'Abrir y cerrar su turno, registrar movimientos y mermas.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Ver su turno',
      edit: 'Abrir y cerrar',
    },
    verbs: {
      read: 've su turno',
      edit: 'abre y cierra su caja',
    },
    adminExceptions: {
      none: 'usar su caja',
      read: 'abrir y cerrar su caja',
    },
    fixedLimit: 'usar una caja',
  },
  {
    id: 'delivery_ops',
    name: 'Pedidos y comandas',
    desc: 'La cola de pedidos y la pantalla de cocina.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Ver pedidos',
      edit: 'Gestionar',
    },
    verbs: {
      read: 've los pedidos',
      edit: 'marca el avance de los pedidos',
    },
    adminExceptions: {
      none: 'ver pedidos',
      read: 'cambiar el estado de los pedidos',
    },
    fixedLimit: 'ver pedidos',
  },
  {
    id: 'crm_read',
    name: 'Clientes',
    desc: 'Buscar clientes y ver su historial y puntos.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Consultar',
    },
    verbs: {
      read: 'consulta clientes',
    },
    adminExceptions: {
      none: 'consultar clientes',
    },
    fixedLimit: 'consultar clientes',
  },
  {
    id: 'voids',
    name: 'Anular ventas',
    desc: 'Anular un ticket indicando el motivo.',
    levelLabels: {
      none: 'Sin acceso',
      edit: 'Anular',
    },
    verbs: {
      edit: 'anula ventas',
    },
    adminExceptions: {
      none: 'anular ventas',
    },
    fixedLimit: 'anular ventas',
  },
  {
    id: 'register_others',
    name: 'Cajas de otros',
    desc: 'Revisar o cerrar el turno de otra persona.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Ver',
      edit: 'Ver y cerrar',
    },
    verbs: {
      read: 've las cajas de otros',
      edit: 'revisa y cierra cajas de otros',
    },
    adminExceptions: {
      none: 'ver cajas de otros',
      read: 'cerrar cajas de otros',
    },
    fixedLimit: 'ver cajas de otros',
  },
  {
    id: 'catalog',
    name: 'Menú y precios',
    desc: 'Productos, opciones, recetas y precios de PedidosYa.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Ver',
      edit: 'Editar',
    },
    verbs: {
      read: 'consulta el menú',
      edit: 'edita el menú y los precios',
    },
    adminExceptions: {
      none: 'ver el menú',
      read: 'editar el menú',
    },
    fixedLimit: 'ver el menú',
  },
  {
    id: 'inventory',
    name: 'Inventario y compras',
    desc: 'Insumos, conteos, compras y proveedores.',
    levelLabels: {
      none: 'Sin acceso',
      edit: 'Gestionar',
    },
    verbs: {
      edit: 'maneja inventario y compras',
    },
    adminExceptions: {
      none: 'manejar inventario',
    },
    fixedLimit: 'manejar inventario',
  },
  {
    id: 'crm_config',
    name: 'Fidelización',
    desc: 'Crear clientes y definir el cashback.',
    levelLabels: {
      none: 'Sin acceso',
      edit: 'Configurar',
    },
    verbs: {
      edit: 'configura la fidelización',
    },
    adminExceptions: {
      none: 'configurar la fidelización',
    },
    fixedLimit: 'configurar la fidelización',
  },
  {
    id: 'reports',
    name: 'Reporte de mermas',
    desc: 'Cuánto se perdió y por qué.',
    levelLabels: {
      none: 'Sin acceso',
      read: 'Consultar',
    },
    verbs: {
      read: 'revisa el reporte de mermas',
    },
    adminExceptions: {
      none: 'ver el reporte de mermas',
    },
    fixedLimit: 'ver el reporte de mermas',
  },
];

/**
 * Permisos que se dan por persona (Usuarios → editar persona), no en la matriz de roles.
 * `roles`: quién puede recibirlo (coincide con los techos de PermissionService::CEILINGS).
 * `defaults`: nivel por rol sin excepción propia (coincide con PermissionService::DEFAULTS).
 * Ver docs/PLAN_DIAS_PASADOS_Y_PERMISOS.md §3.2 y docs/PLAN_DESCUENTOS_CAJA.md §3.2.
 */
export const PERSON_PERMISSION_MODULES = [
  {
    id: 'sales_history',
    name: 'Ventas de días pasados',
    desc: 'Control diario: cargar, editar y anular ventas de otros días, y abrir la caja de un día pasado.',
    levelLabels: { none: 'Sin acceso', edit: 'Cargar y corregir' },
    badge: { none: 'Sin días pasados', edit: 'Días pasados' },
    roles: ['admin'],
    defaults: { admin: 'edit' },
  },
  {
    id: 'register_corrections',
    name: 'Corregir cajas cerradas',
    desc: 'Reabrir una caja ya cerrada, registrar lo que faltó y volver a cerrarla con un motivo.',
    levelLabels: { none: 'Sin acceso', edit: 'Corregir' },
    badge: { none: 'Sin corregir cajas', edit: 'Corrige cajas' },
    roles: ['admin'],
    defaults: { admin: 'none' },
  },
  {
    id: 'finance',
    name: 'Finanzas',
    desc: 'Ver resultados, costos y punto de equilibrio. Con «Editar», además registrar gastos y liquidaciones.',
    levelLabels: { none: 'Sin acceso', read: 'Ver', edit: 'Editar' },
    badge: { none: 'Sin finanzas', read: 'Finanzas: ver', edit: 'Finanzas' },
    roles: ['admin'],
    defaults: { admin: 'none' },
  },
  {
    id: 'pos_discounts',
    name: 'Descuentos en caja',
    desc: 'Aplicar un descuento con motivo al cobrar y en Control diario. Cajero: hasta el 20 %. Administrador: hasta el 100 % (cortesía).',
    levelLabels: { none: 'Sin acceso', edit: 'Descontar' },
    badge: { none: 'Sin descuentos', edit: 'Descuentos' },
    roles: ['admin', 'cashier'],
    defaults: { admin: 'none', cashier: 'none' },
  },
];

/** ¿Puede una persona de ese rol recibir este permiso por persona? */
export function canReceivePerson(role, moduleId) {
  return Boolean(PERSON_PERMISSION_MODULES.find(m => m.id === moduleId)?.roles.includes(role));
}

/** Permisos por persona que se pueden dar a ese rol. */
export function personModulesFor(role) {
  return PERSON_PERMISSION_MODULES.filter(m => m.roles.includes(role));
}

/** Nivel de un permiso por persona según su rol, sin excepción propia. */
export function personRoleLevel(role, moduleId) {
  if (role === 'super_admin') return 'edit';
  return PERSON_PERMISSION_MODULES.find(m => m.id === moduleId)?.defaults[role] ?? 'none';
}

/** Insignias de la lista de Usuarios: los permisos por persona que difieren de su rol. */
export function personBadges(role, overrides = {}) {
  if (!overrides) return [];
  return personModulesFor(role)
    .filter(m => overrides[m.id] && overrides[m.id] !== personRoleLevel(role, m.id))
    .map(m => ({ id: m.id, label: m.badge[overrides[m.id]], grants: overrides[m.id] !== 'none' }));
}

/**
 * Une elementos con comas y la conjunción 'y' (o 'e' si empieza con i/hi).
 */
export function joinWithAnd(items) {
  if (!items || items.length === 0) return '';
  if (items.length === 1) return items[0];
  const last = items[items.length - 1];
  const rest = items.slice(0, -1).join(', ');
  const conjunction = last.toLowerCase().startsWith('i') || last.toLowerCase().startsWith('hi') ? 'e' : 'y';
  return `${rest} ${conjunction} ${last}`;
}

/**
 * Une elementos con la conjunción disyuntiva 'ni'.
 */
export function joinWithNeither(items) {
  if (!items || items.length === 0) return '';
  if (items.length === 1) return items[0];
  return items.slice(0, -1).join(', ') + ' ni ' + items[items.length - 1];
}

/**
 * Genera la frase resumen para el rol especificado según sus niveles activos.
 *
 * @param {string} role 'kitchen' | 'cashier' | 'admin'
 * @param {Function|Object} levelOf (module: string) => string ('none'|'read'|'edit') o mapa de niveles
 * @returns {string}
 */
export function summaryPhrase(role, levelOf) {
  const getLevel = typeof levelOf === 'function' ? levelOf : (m) => levelOf?.[m] ?? 'none';

  const subjects = {
    kitchen: 'Cocina',
    cashier: 'El cajero',
    admin: 'El administrador',
  };
  const subject = subjects[role] || 'El usuario';

  // Recopilar acciones activas
  const activeVerbs = [];
  PERMISSION_MODULES.forEach(mod => {
    const lvl = getLevel(mod.id);
    if (lvl && lvl !== 'none') {
      const verb = mod.verbs[lvl];
      if (verb) activeVerbs.push(verb);
    }
  });

  if (activeVerbs.length === 0) {
    return `${subject} no tiene ningún permiso: al entrar verá «Acceso no disponible».`;
  }

  // Lógica particular para Administrador
  if (role === 'admin') {
    // Techos operativos del admin para los 10 módulos:
    // pos: edit, register_self: edit, delivery_ops: edit, crm_read: read, voids: edit,
    // register_others: edit, catalog: edit, inventory: edit, crm_config: edit, reports: read
    const adminMax = {
      pos: 'edit',
      register_self: 'edit',
      delivery_ops: 'edit',
      crm_read: 'read',
      voids: 'edit',
      register_others: 'edit',
      catalog: 'edit',
      inventory: 'edit',
      crm_config: 'edit',
      reports: 'read',
    };

    const exceptions = [];
    PERMISSION_MODULES.forEach(mod => {
      const current = getLevel(mod.id);
      const max = adminMax[mod.id];
      if (current !== max) {
        const exceptionText = mod.adminExceptions[current];
        if (exceptionText) {
          exceptions.push(exceptionText);
        }
      }
    });

    if (exceptions.length === 0) {
      return 'El administrador puede todo lo operativo: ventas, cajas, pedidos, menú, inventario y clientes.';
    }

    if (exceptions.length <= 4) {
      return `El administrador puede todo lo operativo excepto ${joinWithAnd(exceptions)}.`;
    }

    // Más de 4 recortes: lista de verbos activos
    return `${subject} ${joinWithAnd(activeVerbs)}.`;
  }

  // Cocina y Cajero
  return `${subject} ${joinWithAnd(activeVerbs)}.`;
}

/**
 * Devuelve el texto informativo de límites fijos al pie de la lista.
 *
 * @param {string} role 'kitchen' | 'cashier' | 'admin'
 * @returns {string}
 */
export function roleBoundariesText(role) {
  const subjects = {
    kitchen: 'Cocina',
    cashier: 'El cajero',
    admin: 'El administrador',
  };
  const subject = subjects[role] || 'El rol';

  // Límite universal de Super Admin
  const superAdminOnly = 'Usuarios, auditoría y respaldos son solo del Super Admin. Finanzas, ventas de días pasados y corregir cajas se dan por persona en Usuarios.';

  if (role === 'admin') {
    return superAdminOnly;
  }

  // Para cocina y cajero, identificar módulos con techo 'none'
  const fixedLimits = [];
  if (role === 'kitchen') {
    // Cocina solo puede aspirar a delivery_ops
    fixedLimits.push(
      'vender',
      'usar una caja',
      'consultar clientes',
      'anular ventas',
      'ver cajas de otros',
      'ver el menú',
      'manejar inventario',
      'configurar la fidelización',
      'ver el reporte de mermas'
    );
  } else if (role === 'cashier') {
    fixedLimits.push(
      'anular ventas',
      'ver cajas de otros',
      'manejar inventario',
      'ver el menú',
      'configurar la fidelización',
      'ver el reporte de mermas'
    );
  }

  if (fixedLimits.length > 0) {
    return `${subject} nunca puede ${joinWithNeither(fixedLimits)}: son límites fijos del sistema. ${superAdminOnly}`;
  }

  return superAdminOnly;
}
