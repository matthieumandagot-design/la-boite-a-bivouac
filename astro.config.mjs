// @ts-check
import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';
import icon from 'astro-icon';
import sitemap from '@astrojs/sitemap';

// https://astro.build/config
export default defineConfig({
  site: 'https://laboiteabivouac.fr',
  integrations: [
    icon(),
    sitemap({
      // Les pages du module commercial n'ont rien à faire dans le sitemap :
      // tant que la boutique est fermée elles renvoient vers l'accueil, et une
      // fois ouverte, un panier ou un espace client n'a pas vocation à être
      // indexé. Elles portent aussi noindex (voir src/components/PageCommerce.astro).
      filter: (page) =>
        !page.includes('/admin') &&
        !['/panier/', '/compte/', '/commande/', '/boutique/'].some((chemin) =>
          page.endsWith(chemin),
        ),
    }),
  ],
  vite: {
    plugins: [tailwindcss()]
  }
});