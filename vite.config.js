import { defineConfig } from 'vite'

export default defineConfig({
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: ['assets/js/admin/admin.js'],
    },
  },
})
