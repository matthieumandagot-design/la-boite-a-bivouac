# Dossier atelier automatique — La Boîte à Bivouac

À chaque commande (ou sur demande depuis l'admin), le site envoie les cotes saisies dans le configurateur
à GitHub Actions, qui construit la Boîte dans FreeCAD et t'envoie le dossier atelier par e-mail.

## Ce qui est généré (dossier `Boite_<référence>_<L>x<l>x<H>`)
- `.FCStd` : modèle paramétrique complet (modifiable dans FreeCAD 1.1)
- `.step` : pièces soudées seules (caisse + cadres de portes et de plateau), pour l'atelier
- `_ensemble.step` : même chose + charnières, verrous, loquets, chaînes
- `_plan.pdf` : plan A3 (vues, débit, cotes et rivetage, paramètres, achats), échelle choisie automatiquement (1:20 à 1:40)
- `_debit.csv` : liste de débit + achats, pour Excel
- `_apercu_*.png` : vue arrière et vue avant plateau ouvert
- `rapport_generation.json` : cotes calculées, masse, nombre de trous de rivets, contrôles

## Règles de calcul (dans `atelier/generer_boite.py`)
| Donnée client | Cote de la Boîte |
|---|---|
| Longueur intérieure de caisse | Longueur = intérieur − 20 mm (1 cm de jeu de chaque côté) |
| Largeur intérieure de caisse | Largeur = intérieur − 20 mm |
| Hauteur de boîte | Hauteur |
| Hauteur des ridelles | Bas du plateau = ridelle + 10 mm ; hauteur du plateau = le reste |
| (déduit) | Barres de toit = largeur + 300 mm ; entraxe = 980 mm, réduit si la boîte est courte |

Refus automatiques (e-mail « ÉCHEC » avec la raison) : boîte hors 900–2 600 × 700–1 400 × 600–1 200 mm,
ridelle hors 170–600 mm, plateau de moins de 150 mm de haut, boîte trop courte pour 2 barres.
Mise en « À VÉRIFIER » : chevauchement détecté par les contrôles, pièce non reliée, ridelle estimée.

## Mise en place (une seule fois)
1. **Fichiers du dépôt** : `atelier/`, `.github/workflows/dossier-atelier.yml`, `public/api/dossier-atelier.php`
   (voir PROMPT_CLAUDE_CODE.md pour l'intégration au configurateur et aux commandes).
2. **Secrets GitHub** (dépôt → Settings → Secrets and variables → Actions) :
   `SMTP_HOST`, `SMTP_PORT` (465 ou 587), `SMTP_USER` (contact@laboiteabivouac.fr), `SMTP_PASSWORD`, `DEST_EMAIL`.
   Serveur SMTP : celui indiqué dans le manager OVH pour la boîte Zimbra.
3. **Jeton GitHub** : Settings (compte) → Developer settings → Fine-grained tokens → accès au seul dépôt
   `la-boite-a-bivouac`, permission *Contents : Read and write*. Le coller directement dans le fichier OVH ci-dessous.
4. **Fichier OVH** : copier `site/config-dossier-atelier.example.php` en `config-dossier-atelier.php`
   dans le dossier parent de `laboiteabivouac/`, et y renseigner le jeton, le dépôt et une clé d'admin aléatoire.

## Utilisation
- **Automatique** : le site appelle `declencher_dossier_atelier()` à la validation de la commande.
- **À la main (devis)** : GitHub → Actions → « Dossier atelier » → *Run workflow* → saisir les cotes.
- Le dossier arrive par e-mail en 10 à 15 min ; il reste téléchargeable 90 jours dans l'exécution GitHub.

## Coût et limites
- Gratuit dans le quota GitHub Actions des dépôts privés (2 000 min/mois sur l'offre gratuite actuelle) :
  environ 10 min par dossier, soit de l'ordre de 150 à 200 dossiers par mois.
- Le premier lancement télécharge FreeCAD (≈ 800 Mo) puis le garde en cache.
- Avant-projet : le dossier reste à relire avant envoi à l'atelier (tenue des barres de toit non validée).

## Tester en local
```
BIVOUAC_CONFIG=atelier/config_exemple.json BIVOUAC_OUT=dossier xvfb-run freecad atelier/generer_boite.py
BIVOUAC_OUT=dossier xvfb-run freecad atelier/exporter_dossier.py
ESSAI_A_BLANC=1 python3 atelier/envoyer_dossier.py dossier
```
