/// <reference types="vitest" />
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// La SPA llama solo a la API interna de TIARE. En desarrollo, Vite hace de proxy hacia Laravel
// para que la cookie de sesión de Sanctum sea del mismo origen (localhost:5173).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '.', 'TIARE_')
  const backend = env.TIARE_BACKEND_URL || 'http://localhost:8000'

  return {
    plugins: [react()],
    build: {
      rollupOptions: {
        output: {
          // MUI y React cambian poco: chunks separados que el navegador puede mantener en caché.
          manualChunks: {
            react: ['react', 'react-dom', 'react-router-dom', '@tanstack/react-query'],
            mui: ['@mui/material', '@emotion/react', '@emotion/styled'],
          },
        },
      },
    },
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': { target: backend, changeOrigin: false },
        '/sanctum': { target: backend, changeOrigin: false },
      },
    },
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test/setup.ts'],
      // Las pruebas de páginas MUI tardan ~3 s cada una; con varios archivos en paralelo superaban los 5 s por defecto.
      testTimeout: 15000,
    },
  }
})
