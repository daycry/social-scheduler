import { build } from 'vite';
await build({ configFile: false, publicDir: false, build: { outDir: 'public/build', emptyOutDir: false,
    lib: { entry: 'resources/js/Studio/render-entry.js', name: 'StudioRender', formats: ['iife'], fileName: () => 'studio-renderer.js' },
} });
