import { defineConfig, globalIgnores } from 'eslint/config'
import nextVitals from 'eslint-config-next/core-web-vitals'
import nextTs from 'eslint-config-next/typescript'

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Workaround: eslint-plugin-react@7.37.5 (transitive via eslint-config-next)
  // calls the removed context.getFilename() API during React version auto-detection,
  // which crashes on ESLint 10. Pinning the version here skips detection.
  // Remove once eslint-config-next ships an eslint-plugin-react@8+ peer.
  {
    settings: {
      react: { version: '19' },
    },
  },
  globalIgnores([
    '.next/**',
    'out/**',
    'build/**',
    'coverage/**',
    'next-env.d.ts',
    'wordpress/**/vendor/**',
  ]),
])

export default eslintConfig
