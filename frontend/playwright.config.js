import { defineConfig, devices } from '@playwright/test';

/**
 * Configuración de Playwright enfocada en dispositivos móviles (Teléfonos Web).
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 30000,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['html', { open: 'never' }], ['list']],
  
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'Mobile Chrome (Pixel 7)',
      use: { 
        ...devices['Pixel 7'],
        // Viewport estándar Android: 412 x 915
      },
    },
    {
      name: 'Mobile Safari (iPhone 14)',
      use: { 
        ...devices['iPhone 14'],
        // Viewport estándar iOS: 390 x 844
      },
    },
    {
      name: 'Small Phone (iPhone SE)',
      use: { 
        ...devices['iPhone SE'],
        // Viewport compacto: 375 x 667
      },
    },
  ],
});
