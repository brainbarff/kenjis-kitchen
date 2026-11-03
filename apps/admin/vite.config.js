import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
export default defineConfig({ root: __dirname, envDir: path.resolve(__dirname, '../..'), plugins: [react()], resolve: { alias: { '@shared': path.resolve(__dirname, '../../packages/shared/src') } }, build: { outDir: path.resolve(__dirname, '../../public-admin'), emptyOutDir: true } });
