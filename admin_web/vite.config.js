import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The API runs on :8000 (PHP built-in server). Proxying /api through Vite
// keeps the browser on one origin in development, so CORS and cookie rules
// behave the same as they will behind a single reverse proxy in production.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
