<script setup>
import { onMounted } from 'vue';
import { useNetworkStore } from './stores/network';

const network = useNetworkStore();

onMounted(() => {
  network.init();
});
</script>

<template>
  <router-view></router-view>
</template>

<style>
/* Los estilos globales están en style.css */
</style>
