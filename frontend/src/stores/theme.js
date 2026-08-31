import { defineStore } from 'pinia';
import { ref, watch } from 'vue';
import { detectHardwareProfile, applyHardwareOptimizations } from '../utils/hardwareDetector';

export const useThemeStore = defineStore('theme', () => {
  const isDark = ref(localStorage.getItem('theme') === 'dark');
  
  const initialProfile = detectHardwareProfile();
  const isPerfLite = ref(initialProfile.isLowSpec);
  const isCompactScreen = ref(initialProfile.isCompact);

  const toggleTheme = () => {
    isDark.value = !isDark.value;
  };

  const togglePerfLite = () => {
    isPerfLite.value = !isPerfLite.value;
    localStorage.setItem('perf_mode', isPerfLite.value ? 'lite' : 'full');
    applyHardwareOptimizations(isPerfLite.value);
  };

  const init = () => {
    if (isDark.value) {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }

    const updated = applyHardwareOptimizations(isPerfLite.value);
    isCompactScreen.value = updated.isCompact;
  };

  watch(isDark, (val) => {
    localStorage.setItem('theme', val ? 'dark' : 'light');
    init();
  });

  return { isDark, isPerfLite, isCompactScreen, toggleTheme, togglePerfLite, init };
});

