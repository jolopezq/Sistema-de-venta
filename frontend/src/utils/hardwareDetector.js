/**
 * Hardware & Viewport Detection Utility for Ohana POS
 * Detects device profile (e.g. Toshiba Satellite AMD A10-4600M, 6GB RAM, 1366x768)
 * and applies optimization classes (.perf-lite, .screen-compact) to documentElement.
 */

export function detectHardwareProfile() {
  const width = window.innerWidth || window.screen.width || 1920;
  const height = window.innerHeight || window.screen.height || 1080;
  const screenWidth = window.screen?.width || width;
  const screenHeight = window.screen?.height || height;

  // 1. Compact screen detection (e.g. 1366x768 laptop screen)
  const isCompact = screenWidth <= 1366 || screenHeight <= 800 || width <= 1366 || height <= 800;

  // 2. Hardware profile detection (CPU cores, RAM, GPU cues)
  const cores = navigator.hardwareConcurrency || 4;
  const memory = navigator.deviceMemory || 8; // GB (if supported by browser)
  
  // Stored user preference overrides auto-detection
  const storedPerf = localStorage.getItem('perf_mode');
  
  let isLowSpec = false;
  if (storedPerf !== null) {
    isLowSpec = storedPerf === 'lite';
  } else {
    // Auto-detect: AMD A10-4600M typically reports 4 cores and <= 6GB RAM, or 1366x768 resolution
    isLowSpec = cores <= 4 || memory <= 6 || isCompact;
  }

  return {
    isCompact,
    isLowSpec,
    cores,
    memory,
    screenWidth,
    screenHeight,
    viewportWidth: width,
    viewportHeight: height
  };
}

export function applyHardwareOptimizations(isPerfLite = null) {
  const profile = detectHardwareProfile();
  const perfLite = isPerfLite !== null ? isPerfLite : profile.isLowSpec;
  const root = document.documentElement;

  if (perfLite) {
    root.classList.add('perf-lite');
  } else {
    root.classList.remove('perf-lite');
  }

  if (profile.isCompact) {
    root.classList.add('screen-compact');
  } else {
    root.classList.remove('screen-compact');
  }

  return { ...profile, isLowSpec: perfLite };
}
