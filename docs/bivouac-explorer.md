# Illustration interactive de La Boîte

Intégration : page `/la-boite`, après le bloc « Le concept ».

- Composant : `src/components/BivouacExplorer.astro`.
- Animation : `src/scripts/bivouac-explorer.ts`.
- Visuel : `public/images/bivouac-explorer/product-atlas-barres.webp` (1145 × 1374).
- Consigne de génération exacte : `docs/bivouac-explorer-prompt.txt`.
- Consigne de correction des barres : `docs/bivouac-explorer-barres-prompt.txt`.

## Direction artistique et sources

Illustration originale réalisée avec l’outil intégré de génération d’images,
à partir de `galerie-caisse-2.jpeg`, `produit-interieur.jpeg`, `produit-34.jpeg`
et `galerie-remorque-3.jpeg`. La photo `produit-34.jpeg` distingue la tablette
avant, côté timon, des deux portes arrière visibles sur les deux premières
références. La tente reste fermée comme sur la dernière photo.

La nouvelle planche contient uniquement la tente fermée, la boîte entière
avec tablette avant déployée sur chaînettes, et la remorque vide. Les trois
éléments partagent une perspective commune. Le fond vert forêt et les
matériaux OSB/acier/galvanisé respectent les références. Export WebP qualité
90. Il s’agit d’une illustration du prototype, pas d’un modèle mécanique coté.

Correction du 12 septembre 2026 : les deux barres fixées à la boîte sont
transversales (rotation de 90° dans le plan du toit, parallèles au bord avant).
Toutes les barres auparavant dessinées sous la tente ont été supprimées par
retouche avec l’outil intégré. La tente repose directement sur les barres de
la boîte. Les masques d’affichage et sa hauteur d’appui ont été ajustés ;
la tablette, l’emboîtement et l’animation de séparation restent inchangés.

## Animation

Le défilement natif pilote une scène Canvas 2D : ensemble emboîté, puis
séparation verticale des trois éléments. Le défilement inverse les rassemble.
La version tente ouverte et son animation de pliage ont été retirées.
Le fond reste celui de la charte ; aucune bibliothèque d’animation ajoutée.

Les visuels conservent leurs proportions sans déformation ni miroir.
L’emboîtement est réglé pour masquer environ le tiers inférieur de la boîte
derrière les ridelles. Celles-ci sont redessinées au premier plan, puis la
tablette est replacée devant la ridelle avant puisqu’elle dépasse du bac.
La frontière d’occlusion suit la remorque pendant la séparation, sans fondu.
Le lissage de 85 ms atténue les crans de molette sans intercepter le scroll.
Le dessin s’arrête à l’équilibre et hors écran. Un recul progressif garde la
vue éclatée entière dans le cadre.

Deux boutons (« En place » / « Vue éclatée ») permettent d’accéder aux étapes. Avec la préférence système
« réduire les animations » ou un écran de moins de 651 px de haut, les étapes
sont manuelles et le long parcours de défilement est désactivé. Une vraie
photo du prototype reste visible si JavaScript ou le chargement du visuel
ne fonctionne pas.

## Vérification

- `npm run build` : réussi.
- Aperçu ordinateur 1440 × 900 et téléphone 390 × 844.
- Positions emboîtée / séparée contrôlées, tente toujours fermée.
- Navigation inverse et absence de débordement horizontal sur mobile.

Aucune publication OVH n’a été effectuée pour cette illustration.
