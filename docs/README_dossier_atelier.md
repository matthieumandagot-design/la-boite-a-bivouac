# Dossier atelier automatique — La Boîte à Bivouac

À chaque demande de dossier offert, à chaque commande (ou sur demande depuis l'admin), le site envoie les cotes saisies dans le configurateur
à GitHub Actions, qui construit la Boîte dans FreeCAD et t'envoie le dossier atelier par e-mail.

## Dossier offert (modèle libre, depuis le 26/10/2026)
La Boîte n'est plus vendue : le configurateur envoie gratuitement ce dossier au visiteur.
1. `/configurateur/` → `POST /api/dossier-gratuit.php` (`action: demander`) : cotes recalculées côté serveur,
   limites du générateur vérifiées, puis email de confirmation avec un lien signé valable 24 h (rien n'est stocké).
2. `/dossier/?t=…` → bouton → `POST /api/dossier-gratuit.php` (`action: confirmer`) : déclenche ce workflow avec,
   en plus des cotes, `destinataire_chiffre` (AES-256-CBC + HMAC-SHA256, clé `CLE_EMAIL`).
3. Le workflow déchiffre l'adresse (`atelier/dechiffrer_destinataire.py`), la masque dans les journaux, et
   `envoyer_dossier.py` envoie le dossier au demandeur + une notification courte, sans pièces jointes, à `ATELIER_DEST_EMAIL`.
   En cas d'échec : message d'excuse au demandeur, dossier d'échec complet à `ATELIER_DEST_EMAIL` (avec l'adresse à recontacter).

Garde-fous : champ piège, 5 demandes/h par IP, 3/jour par adresse, lien à usage unique, 20 générations/h au total.
Les commandes et le lancement manuel fonctionnent comme avant (tout part à `ATELIER_DEST_EMAIL`).

### Mise en place du dossier offert (une seule fois)
1. Générer une clé : `openssl rand -base64 32` (ou, sous PowerShell 7 :
   `[Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))`).
2. La coller dans le secret GitHub **`ATELIER_CLE_EMAIL`** (Settings → Secrets and variables → Actions).
3. La coller aussi dans `config-dossier-atelier.php` sur OVH (dossier parent de `laboiteabivouac/`), clé `'CLE_EMAIL'`.
   Sans elle, le configurateur répond « service momentanément indisponible ».
4. Vérifier que le SMTP du site (`api/config.php`, celui de la newsletter) accepte d'envoyer à des adresses extérieures :
   c'est lui qui envoie l'email de confirmation.

### Quota
Sur un dépôt privé, l'offre gratuite de GitHub Actions donne 2 000 min/mois, soit environ 150 à 200 dossiers.
Les dépôts publics n'ont pas de limite de minutes sur les runners standard : rendre le dépôt public (cohérent avec
des plans libres) lèverait la limite, à condition de vérifier qu'aucun secret ni donnée client n'est versionné.

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
   `ATELIER_SMTP_HOST`, `ATELIER_SMTP_PORT` (465 ou 587), `ATELIER_SMTP_USER` (contact@laboiteabivouac.fr), `ATELIER_SMTP_PASSWORD`, `ATELIER_DEST_EMAIL`, et `ATELIER_CLE_EMAIL` pour le dossier offert (voir plus haut). Prefixe ATELIER_ volontaire : `SMTP_PASSWORD` sert deja au relais Brevo de la newsletter, le reutiliser la casserait.
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
