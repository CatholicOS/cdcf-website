import type { Config } from 'tailwindcss'
import typography from '@tailwindcss/typography'

const config: Config = {
  content: [
    './app/**/*.{ts,tsx}',
    './components/**/*.{ts,tsx}',
    './lib/**/*.{ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        'cdcf-navy': {
          50: '#eef1f8',
          100: '#d5daea',
          200: '#aab5d5',
          300: '#7f90c0',
          400: '#556bab',
          500: '#2a4696',
          600: '#213463',
          700: '#1a2a4f',
          800: '#131f3b',
          900: '#0d1528',
          950: '#060a14',
          DEFAULT: '#213463',
        },
        'cdcf-gold': {
          50: '#faf8ef',
          100: '#f0ecd4',
          200: '#e1d9a9',
          300: '#d2c67e',
          400: '#c3b353',
          500: '#9a8432',
          600: '#7b6a28',
          700: '#5c4f1e',
          800: '#3d3514',
          900: '#1f1a0a',
          950: '#0f0d05',
          DEFAULT: '#9a8432',
        },
      },
      fontFamily: {
        serif: ['var(--font-merriweather)', 'Georgia', 'serif'],
        sans: ['var(--font-inter)', 'system-ui', 'sans-serif'],
        display: ['var(--font-playfair-display)', 'Georgia', 'serif'],
      },
    },
  },
  plugins: [typography],
}

export default config
