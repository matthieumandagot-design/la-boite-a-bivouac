# La Boîte à Bivouac

Site vitrine avec blog pour La Boîte à Bivouac : une box de stockage modulaire et démontable pour remorque **ERDE 193**, conçue pour transporter une tente de toit et un vélo VTT XXL.

Construit avec [Astro](https://astro.build) et [Tailwind CSS](https://tailwindcss.com).

## Structure du projet

```text
/
├── public/
├── src/
│   ├── components/       # Header, Footer
│   ├── content/blog/     # Articles de blog (Markdown)
│   ├── content.config.ts # Schéma de la collection "blog"
│   ├── layouts/          # Layout commun (Layout.astro)
│   ├── pages/
│   │   ├── index.astro       # Accueil
│   │   ├── produit.astro     # Présentation du produit
│   │   ├── contact.astro     # Contact
│   │   └── blog/
│   │       ├── index.astro   # Liste des articles
│   │       └── [id].astro    # Détail d'un article
│   ├── styles/global.css # Palette et thème Tailwind
│   └── consts.ts          # Constantes du site (titre, nav)
└── package.json
```

## Commandes

| Commande            | Action                                       |
| :------------------ | :-------------------------------------------- |
| `npm install`        | Installe les dépendances                      |
| `npm run dev`         | Démarre le serveur local sur `localhost:4321` |
| `npm run build`       | Build de production dans `./dist/`            |
| `npm run preview`     | Prévisualise le build localement               |

## Ajouter un article de blog

Créer un fichier Markdown dans `src/content/blog/` avec le frontmatter suivant :

```md
---
title: "Titre de l'article"
description: "Résumé court de l'article"
pubDate: 2026-07-10
---

Contenu de l'article en Markdown.
```
