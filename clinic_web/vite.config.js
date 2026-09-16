import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The API runs on :8000 (PHP built-in server). Proxying /api through Vite
// keeps the browser on one origin in development, so CORS and cookie rules
// behave the same as they will behind a single reverse proxy in production.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    // Reachable from a phone on the same Wi-Fi, so the clinic side can be
    // shown on a handset next to the patient app.
    host: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
