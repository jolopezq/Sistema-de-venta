import { createRouter, createWebHistory } from 'vue-router';
import Login from '../views/Login.vue';
import Pos from '../views/Pos.vue';
import Turno from '../views/Turno.vue';
import Menu from '../views/Menu.vue';
import Delivery from '../views/Delivery.vue';

import AdminUsers from '../views/AdminUsers.vue';
import AuditLogs from '../views/AuditLogs.vue';
import InventoryList from '../views/InventoryList.vue';
import RetroactiveSales from '../views/RetroactiveSales.vue';
import SinAcceso from '../views/SinAcceso.vue';

import { screensFor } from '../utils/roleScreens.js';

export function landingFor(authStore) {
  if (!authStore?.user) return '/login';
  const res = screensFor(authStore.user.role, (mod) => authStore.effective_permissions?.[mod] ?? 'none');
  return res.landing;
}

const routes = [
  { path: '/', redirect: '/pos' },
  { path: '/login', name: 'Login', component: Login },
  { path: '/sin-acceso', name: 'SinAcceso', component: SinAcceso, meta: { requiresAuth: true } },
  { path: '/pos', name: 'Pos', component: Pos, meta: { requiresAuth: true, requiredPermission: { module: 'pos', level: 'read' }, noKitchen: true } },
  { path: '/turno', name: 'Turno', component: Turno, meta: { requiresAuth: true, requiredPermission: { module: 'register_self', level: 'read' }, noKitchen: true } },
  { path: '/menu', name: 'Menu', component: Menu, meta: { requiresAuth: true, requiredPermission: { module: 'catalog', level: 'read' } } },
  { path: '/delivery', name: 'Delivery', component: Delivery, meta: { requiresAuth: true, requiredPermission: { module: 'delivery_ops', level: 'read' } } },
  { path: '/inventario', name: 'Inventario', component: InventoryList, meta: { requiresAuth: true, requiredPermission: { module: 'inventory', level: 'read' } } },
  { path: '/admin/ventas-retroactivas', name: 'RetroactiveSales', component: RetroactiveSales, meta: { requiresAuth: true, requiredPermission: { module: 'pos', level: 'edit' }, adminOnly: true } },
  { path: '/users', name: 'AdminUsers', component: AdminUsers, meta: { requiresAuth: true, requiredPermission: { module: 'users', level: 'read' }, superAdminOnly: true } },
  { path: '/audit-logs', name: 'AuditLogs', component: AuditLogs, meta: { requiresAuth: true, requiredPermission: { module: 'audit', level: 'read' }, superAdminOnly: true } },
  { path: '/finanzas', name: 'Finance', component: () => import('../views/Finance.vue'), meta: { requiresAuth: true, superAdminOnly: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to, from, next) => {
  const { useAuthStore } = await import('../stores/auth.js');
  const authStore = useAuthStore();
  
  if (authStore.token && !authStore.user) {
    await authStore.fetchUser();
  }
  
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return next('/login');
  }

  if (to.path === '/login' && authStore.isAuthenticated) {
    return next(landingFor(authStore));
  }

  if (to.path === '/sin-acceso') {
    return next();
  }

  const redirectToSafe = () => {
    const landing = landingFor(authStore);
    if (to.path === landing) {
      return next('/sin-acceso');
    }
    return next(landing);
  };

  if (to.meta.noKitchen && authStore.user?.role === 'kitchen') {
    return redirectToSafe();
  }

  if (to.meta.superAdminOnly && authStore.user?.role !== 'super_admin') {
    return redirectToSafe();
  }

  if (to.meta.adminOnly && !['admin', 'super_admin'].includes(authStore.user?.role)) {
    return redirectToSafe();
  }

  if (to.meta.requiredPermission && !authStore.hasPermission(to.meta.requiredPermission.module, to.meta.requiredPermission.level)) {
    return redirectToSafe();
  }

  next();
});
