import { defineConfig } from 'vite';

export default defineConfig(({ mode }) => ({
	base: '/assets/',
	publicDir: false,
	build: {
		outDir: 'www/assets',
		emptyOutDir: true,
		manifest: true,
		assetsDir: '',
		sourcemap: mode === 'development',
		rollupOptions: {
			input: {
				main: 'assets/main.js',
			},
		},
	},
	css: {
		preprocessorOptions: {
			scss: {
				quietDeps: true,
				silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
			},
		},
	},
}));
