import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'node',
    globals: false,
    include: ['lib/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['lib/wordpress/**/*.ts'],
      exclude: ['lib/wordpress/**/*.test.ts'],
      reporter: ['text', 'html'],
    },
  },
})
