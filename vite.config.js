import { defineConfig } from "vite";
import { resolve } from 'path'
import sass from 'sass'

// import basicSsl from '@vitejs/plugin-basic-ssl'

// vite.config.js
export default defineConfig({
  build: {
    assetsDir: '',
    // generate manifest.json in outDir
    manifest: true,
    sourcemap: true,
    rollupOptions: {
      // outDir: 'dist',
      // overwrite default .html entry
      input: [
        resolve(__dirname, './resources/js/frontend.js'),
      ],
      output: {
        assetFileNames: (assetInfo) => {
          let extType = assetInfo.name.split('.').at(1);
          if (/png|jpe?g|svg|gif|tiff|bmp|ico/i.test(extType)) {
            extType = 'img';
          }
          if (/woff|woff2|ttf|eot/i.test(extType)) {
            extType = 'fonts';
          }
          return `${extType}/[name][extname]`;
        },
        entryFileNames: "js/[name].js",
      },
    },
  },
  publicDir: './assets',
  base: './',
  plugins: [
    {
      name: 'php',
      handleHotUpdate({ file, server }) {
        if (file.endsWith('.php')) {
          server.ws.send({ type: 'full-reload', path: '*' });
        }
      },
    },
  ]
})