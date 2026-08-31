import { createRouter, createWebHistory } from 'vue-router';
import Login from '../views/Login.vue';
import Pos from '../views/Pos.vue';
import Turno from '../views/Turno.vue';
import Menu from '../views/Menu.vue';
import Delivery from '../views/Delivery.vue';

import AdminUsers from '../views/AdminUsers.vue';
import AuditLogs from '../views/AuditLogs.vue';
import InventoryList from '../views/InventoryList.vue';

const routes = [
  { path: '/', redirect: '/pos' },
  { path: '/login', name: 'Login', component: Login },
  { path: '/pos', name: 'Pos', component: Pos, meta: { requiresAuth: true } },
  { path: '/turno', name: 'Turno', component: Turno, meta: { requiresAuth: true } },
  { path: '/menu', name: 'Menu', component: Menu, meta: { requiresAuth: true } },
  { path: '/delivery', name: 'Delivery', component: Delivery, meta: { requiresAuth: true } },
  { path: '/inventario', name: 'Inventario', component: InventoryList, meta: { requiresAuth: true, adminOnly: true } },
  { path: '/users', name: 'AdminUsers', component: AdminUsers, meta: { requiresAuth: true, superAdminOnly: true } },
  { path: '/audit-logs', name: 'AuditLogs', component: AuditLogs, meta: { requiresAuth: true, superAdminOnly: true } },
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
    next('/login');
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/pos');
  } else if (to.meta.superAdminOnly && authStore.user?.role !== 'super_admin') {
    next('/pos');
  } else if (to.meta.adminOnly && !['admin', 'super_admin'].includes(authStore.user?.role)) {
    next('/pos');
  } else {
    next();
  }
});
