<script setup>
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useNetworkStore } from './stores/network';
import { useAuthStore } from './stores/auth';
import { useThemeStore } from './stores/theme';
import AppShell from './components/AppShell.vue';

const network = useNetworkStore();
const theme = useThemeStore();
const auth = useAuthStore();
const route = useRoute();

onMounted(() => {
  network.init();
  theme.init();
});

// Show shell only when authenticated and not on login page
const useShell = computed(() => {
  return auth.isAuthenticated && route.path !== '/login';
});
</script>

<template>
  <AppShell v-if="useShell">
    <router-view />
  </AppShell>
  <router-view v-else />
</template>

<style>
/* Los estilos globales están en style.css */
</style>
