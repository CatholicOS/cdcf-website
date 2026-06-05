import { defineConfig } from 'vitest/config'
import path from 'node:path'

export default defineConfig({
  resolve: {
    alias: {
      // Next.js's `server-only` package throws on import to guard
      // against bundling server modules into the client. In Vitest
      // (Node, no client bundle) that guard is a false positive, so
      // alias the module to an empty stub.
      'server-only': path.resolve(__dirname, 'tests/stubs/server-only.ts'),
      // Resolve the `@/` alias used by source for absolute imports.
      '@': path.resolve(__dirname),
    },
  },
  test: {
    environment: 'node',
    globals: false,
    include: ['lib/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['lib/**/*.ts'],
      exclude: ['lib/**/*.test.ts'],
      reporter: ['text', 'html', 'lcov'],
    },
  },
})
