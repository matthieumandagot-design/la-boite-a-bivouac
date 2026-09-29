# -*- coding: utf-8 -*-
"""La Boîte à Bivouac — envoi du dossier atelier par e-mail (étape 3, Python standard, sans FreeCAD).
Variables d'environnement : SMTP_HOST, SMTP_PORT (465 SSL ou 587 STARTTLS), SMTP_USER, SMTP_PASSWORD,
DEST_EMAIL (destinataire), RUN_URL (lien vers l'exécution GitHub, facultatif). Usage : python3 envoyer_dossier.py dossier/"""
import os, sys, json, glob, smtplib, ssl, mimetypes
from email.message import EmailMessage

dossier = sys.argv[1] if len(sys.argv) > 1 else 'dossier'
r = json.load(open(os.path.join(dossier, 'rapport_generation.json'), encoding='utf-8'))
statut = {'ok': 'Dossier atelier prêt', 'a_verifier': 'Dossier atelier À VÉRIFIER', 'erreur': 'ÉCHEC du dossier atelier'}[r.get('statut', 'erreur')]
b = r.get('boite_mm', {})
lignes = ['%s — réf. %s' % (statut, r.get('reference', '?')), '']
if r.get('remorque'):
    lignes.append('Remorque : %s' % r['remorque'])
if r.get('entree'):
    e = r['entree']
    lignes.append('Saisie client : caisse %s x %s cm, ridelle %s cm, boîte %s cm de haut' % (
        e['longueur_int_cm'], e['largeur_int_cm'], e['hauteur_ridelle_cm'], e['hauteur_boite_cm']))
if b:
    lignes += ['Boîte : %d x %d x %d mm' % (b['longueur'], b['largeur'], b['hauteur']),
               'Plateau : bas à %d mm, cadre de %d mm de haut' % (b['bas_plateau'], b['hauteur_plateau']),
               'Barres de toit : %d mm, entraxe %d mm' % (b['longueur_barres'], b['entraxe_barres'])]
if 'masse_kg' in r:
    lignes.append('Masse théorique : %s kg — %s pièces soudées — %s trous de rivets — plan au %s' % (
        r['masse_kg'], r.get('pieces_soudees', '?'), r.get('trous_rivets', '?'), r.get('echelle_plan', '?')))
for titre, cle in (('ERREURS', 'erreurs'), ('À VÉRIFIER', 'alertes')):
    if r.get(cle):
        lignes += ['', titre + ' :'] + ['- ' + x for x in r[cle]]
lignes += ['', 'Avant-projet généré automatiquement depuis le configurateur : à relire avant envoi à l\'atelier.']
if os.environ.get('RUN_URL'):
    lignes.append('Exécution et fichiers : ' + os.environ['RUN_URL'])

msg = EmailMessage()
msg['Subject'] = '[Boîte à Bivouac] %s — %s' % (statut, r.get('reference', '?'))
msg['From'] = os.environ.get('SMTP_USER', 'contact@laboiteabivouac.fr')
msg['To'] = os.environ.get('DEST_EMAIL', msg['From'])
msg.set_content('\n'.join(lignes))
total = 0
for f in sorted(glob.glob(os.path.join(dossier, '*'))):
    if f.endswith(('.pdf', '.csv', '.step', '.FCStd', '.png', '.json')) and total + os.path.getsize(f) < 20e6:
        ctype = mimetypes.guess_type(f)[0] or 'application/octet-stream'
        maj, mineur = ctype.split('/', 1)
        msg.add_attachment(open(f, 'rb').read(), maintype=maj, subtype=mineur, filename=os.path.basename(f))
        total += os.path.getsize(f)

if os.environ.get('ESSAI_A_BLANC'):
    print(msg['Subject']); print(msg.get_body().get_content())
    print('Pièces jointes :', [p.get_filename() for p in msg.iter_attachments()], '%.1f Mo' % (total / 1e6))
    sys.exit(0)
port = int(os.environ.get('SMTP_PORT', '465'))
ctx = ssl.create_default_context()
if port == 465:
    with smtplib.SMTP_SSL(os.environ['SMTP_HOST'], port, context=ctx, timeout=60) as s:
        s.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD']); s.send_message(msg)
else:
    with smtplib.SMTP(os.environ['SMTP_HOST'], port, timeout=60) as s:
        s.starttls(context=ctx); s.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD']); s.send_message(msg)
print('E-mail envoyé :', msg['Subject'], '(%d pièces jointes, %.1f Mo)' % (len(msg.get_payload()) - 1, total / 1e6))
