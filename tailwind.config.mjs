// tailwind.config.mjs — La Boîte à Bivouac
// Couleurs extraites des logos officiels (Logo.png + Logo_image.png)
export default {
  content: ['./src/**/*.{astro,html,js,jsx,ts,tsx,vue,svelte}'],
  theme: {
    extend: {
      colors: {
        forest: {
          DEFAULT: '#122218',
          lt:      '#1E3A28',
          dk:      '#0A1610',
        },
        gold: {
          DEFAULT: '#E8B428',
          lt:      '#F2D878',
          dk:      '#B88A18',
        },
        ivory:      '#E8E0C8',
        cream:      '#F5ECD8',
        anthracite: '#1A1A1A',
        silver:     '#C0C8C8',
        terra: {
          DEFAULT: '#B85438',
          lt:      '#D47050',
          dk:      '#8B3A22',
        },
      },
      fontFamily: {
        heading: ['Bebas Neue', 'Impact', 'Arial Narrow', 'sans-serif'],
        sub:     ['Oswald', 'Arial', 'sans-serif'],
        body:    ['Inter', 'Helvetica Neue', 'Arial', 'sans-serif'],
        accent:  ['Caveat', 'cursive'],
      },
      boxShadow: {
        brand:    '0 4px 12px rgba(18,34,24,0.18)',
        'brand-lg': '0 8px 28px rgba(18,34,24,0.22)',
      },
    },
  },
  plugins: [],
}
