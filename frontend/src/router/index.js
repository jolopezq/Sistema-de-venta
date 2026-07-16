import { createRouter, createWebHistory } from 'vue-router';
import Login from '../views/Login.vue';
import Pos from '../views/Pos.vue';
import Turno from '../views/Turno.vue';
import Admin from '../views/Admin.vue';
import Delivery from '../views/Delivery.vue';

const routes = [
  { path: '/', redirect: '/pos' },
  { path: '/login', name: 'Login', component: Login },
  { path: '/pos', name: 'Pos', component: Pos, meta: { requiresAuth: true } },
  { path: '/turno', name: 'Turno', component: Turno, meta: { requiresAuth: true } },
  { path: '/admin', name: 'Admin', component: Admin, meta: { requiresAuth: true } },
  { path: '/delivery', name: 'Delivery', component: Delivery, meta: { requiresAuth: true } },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to, from, next) => {
  const { useAuthStore } = await import('../stores/auth.js');
  const authStore = useAuthStore();
  
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login');
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/pos');
  } else {
    next();
  }
});
