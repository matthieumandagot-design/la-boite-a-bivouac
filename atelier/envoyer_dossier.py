# -*- coding: utf-8 -*-
"""La Boîte à Bivouac — envoi du dossier par e-mail (étape 3, Python standard, sans FreeCAD).

Deux cas :
- DOSSIER OFFERT (modèle libre, 26/10/2026) : DEST_DEMANDEUR est renseigné (adresse déchiffrée par
  dechiffrer_destinataire.py). Le demandeur reçoit le dossier complet, prêt à transmettre à un atelier
  de soudure ; DEST_EMAIL (Matthieu) reçoit une notification courte, sans pièces jointes. En cas d'échec,
  le demandeur reçoit un message d'excuse et Matthieu le dossier d'échec complet.
- DOSSIER ATELIER (commande, admin, lancement manuel) : comme avant, tout part à DEST_EMAIL.

Variables d'environnement : SMTP_HOST, SMTP_PORT (465 SSL ou 587 STARTTLS), SMTP_USER, SMTP_PASSWORD,
DEST_EMAIL, DEST_DEMANDEUR (facultatif), RUN_URL (facultatif). ESSAI_A_BLANC=1 affiche sans envoyer.
Usage : python3 envoyer_dossier.py dossier/
"""
import os, sys, json, glob, smtplib, ssl, mimetypes, html
from email.message import EmailMessage
from email.utils import make_msgid

SITE = 'https://laboiteabivouac.fr'
CONTACT = 'contact@laboiteabivouac.fr'
LICENCE = 'Creative Commons BY-SA 4.0'
LICENCE_URL = 'https://creativecommons.org/licenses/by-sa/4.0/deed.fr'
# Une pièce jointe encodée pèse un tiers de plus : 15 Mo bruts restent sous les 25 Mo de Gmail et d'Outlook.
PLAFOND_PJ = 15e6

dossier = sys.argv[1] if len(sys.argv) > 1 else 'dossier'
r = json.load(open(os.path.join(dossier, 'rapport_generation.json'), encoding='utf-8'))
statut_code = r.get('statut', 'erreur')
if statut_code not in ('ok', 'a_verifier', 'erreur'):
    statut_code = 'erreur'
reference = r.get('reference', '?')
b = r.get('boite_mm', {})
demandeur = os.environ.get('DEST_DEMANDEUR', '').strip()
expediteur = os.environ.get('SMTP_USER', CONTACT)
interne = os.environ.get('DEST_EMAIL', expediteur)


# ------------------------------------------------------------------ contenu commun

def lignes_techniques():
    lignes = []
    if r.get('remorque'):
        lignes.append('Remorque : %s' % r['remorque'])
    if r.get('entree'):
        e = r['entree']
        lignes.append('Saisie : caisse %s x %s cm, ridelle %s cm, boîte %s cm de haut' % (
            e['longueur_int_cm'], e['largeur_int_cm'], e['hauteur_ridelle_cm'], e['hauteur_boite_cm']))
    if b:
        lignes += ['Boîte : %d x %d x %d mm' % (b['longueur'], b['largeur'], b['hauteur']),
                   'Plateau : bas à %d mm, cadre de %d mm de haut' % (b['bas_plateau'], b['hauteur_plateau']),
                   'Barres de toit : %d mm, entraxe %d mm' % (b['longueur_barres'], b['entraxe_barres'])]
    if 'masse_kg' in r:
        lignes.append('Masse théorique : %s kg — %s pièces soudées — %s trous de rivets — plan au %s' % (
            r['masse_kg'], r.get('pieces_soudees', '?'), r.get('trous_rivets', '?'), r.get('echelle_plan', '?')))
    return lignes


def description(nom):
    """Ce que contient chaque fichier, dit simplement."""
    if nom.endswith('_plan.pdf'):
        return 'plan de fabrication A3 : vues, cotes, débit, rivetage, achats'
    if nom.endswith('_ensemble.step'):
        return 'modèle 3D complet, avec charnières, verrous, loquets et chaînes'
    if nom.endswith('.step'):
        return 'modèle 3D des pièces soudées, à ouvrir dans tout logiciel de CAO'
    if nom.endswith('.FCStd'):
        return 'modèle paramétrique FreeCAD (logiciel libre), modifiable'
    if nom.endswith('_debit.csv'):
        return 'liste de débit et d\'achats, à ouvrir dans un tableur'
    if nom.endswith('.png'):
        return 'aperçu en image'
    if nom.endswith('.json'):
        return 'rapport technique de la génération'
    return ''


def ordre(nom):
    """Si tout ne tient pas sous le plafond, l'essentiel part en premier."""
    if nom.endswith('_ensemble.step'):
        return 9
    for i, fin in enumerate(('_plan.pdf', '.png', '_debit.csv', '.step', '.FCStd', '.json')):
        if nom.endswith(fin):
            return i
    return 10


def selection(plafond):
    """Fichiers à joindre, par ordre d'importance, sous le plafond. Renvoie (chemins, écartés, total)."""
    fichiers = [f for f in glob.glob(os.path.join(dossier, '*'))
                if f.endswith(('.pdf', '.csv', '.step', '.FCStd', '.png', '.json'))]
    fichiers.sort(key=lambda f: (ordre(os.path.basename(f)), os.path.basename(f)))
    choisis, ecartes, total = [], [], 0
    for f in fichiers:
        taille = os.path.getsize(f)
        if total + taille > plafond:
            ecartes.append(os.path.basename(f))
            continue
        choisis.append(f)
        total += taille
    return choisis, ecartes, total


def attacher(msg, chemins):
    for f in chemins:
        ctype = mimetypes.guess_type(f)[0] or 'application/octet-stream'
        maj, mineur = ctype.split('/', 1)
        msg.add_attachment(open(f, 'rb').read(), maintype=maj, subtype=mineur, filename=os.path.basename(f))


def gabarit(titre, corps_html):
    """Habillage à la marque, aligné sur public/lib/emails-commande.php."""
    police = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif"
    return ('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" '
            'content="width=device-width, initial-scale=1"><title>La Boîte à Bivouac</title></head>'
            '<body style="margin:0;padding:0;background:#F4F2EE;">'
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F2EE;">'
            '<tr><td align="center" style="padding:24px 12px;">'
            '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;'
            'background:#FFFFFF;border:1px solid #E4E0D8;border-radius:16px;">'
            '<tr><td align="center" style="background:#122218;border-radius:16px 16px 0 0;padding:26px 24px 22px;">'
            '<img src="' + SITE + '/images/logo-badge.png" width="60" height="60" alt="La Boîte à Bivouac" '
            'style="display:block;border:0;margin:0 auto 12px;">'
            '<div style="font-family:' + police + ';font-size:15px;font-weight:700;letter-spacing:.16em;'
            'text-transform:uppercase;color:#F5ECD8;">La Boîte à Bivouac</div>'
            '<div style="width:54px;height:3px;background:#E8B428;margin:14px auto 0;font-size:0;line-height:0;">&nbsp;</div>'
            '</td></tr><tr><td style="padding:30px 28px 26px;font-family:' + police + ';font-size:16px;'
            'line-height:1.6;color:#1A1A1A;">'
            '<p style="margin:0 0 14px;font-size:20px;font-weight:700;">' + html.escape(titre) + '</p>'
            + corps_html +
            '</td></tr><tr><td style="border-top:1px solid #E4E0D8;padding:18px 28px 24px;font-family:' + police +
            ';font-size:12px;line-height:1.6;color:#6A6A62;">'
            'Plans diffusés sous licence <a href="' + LICENCE_URL + '" style="color:#6A6A62;">' + LICENCE + '</a>.<br>'
            '<a href="' + SITE + '" style="color:#6A6A62;">laboiteabivouac.fr</a> — une question&nbsp;? '
            'Répondez simplement à cet email.</td></tr></table></td></tr></table></body></html>')


def para(texte, style=''):
    return '<p style="margin:0 0 14px;%s">%s</p>' % (style, texte)


def liste(elements):
    return ('<ul style="margin:0 0 14px;padding-left:20px;">'
            + ''.join('<li style="margin:0 0 4px;">%s</li>' % e for e in elements) + '</ul>')


A_SAVOIR = [
    'Ce dossier est un avant-projet généré automatiquement : faites-le relire par un professionnel avant fabrication.',
    'La tenue des barres de toit n\'a pas été validée par calcul ni par essai certifié. Respectez la charge de '
    'votre remorque (PTAC) et le code de la route.',
    'Les plans sont fournis tels quels, sans garantie : la fabrication et l\'usage restent sous votre responsabilité.',
    'Licence %s : utilisez, modifiez et partagez ces plans, y compris pour fabriquer, en citant La Boîte à Bivouac '
    'et en partageant vos modifications dans les mêmes conditions.' % LICENCE,
]


# ------------------------------------------------------------------ messages

def message_demandeur():
    """Le dossier complet, pour la personne qui l'a demandé."""
    msg = EmailMessage()
    msg['Subject'] = 'Votre dossier de fabrication La Boîte à Bivouac — réf. %s' % reference
    msg['From'] = 'La Boîte à Bivouac <%s>' % expediteur
    msg['To'] = demandeur
    msg['Reply-To'] = CONTACT
    msg['Message-ID'] = make_msgid(domain='laboiteabivouac.fr')

    # Les pièces jointes d'abord, pour savoir quoi annoncer dans le texte.
    chemins, ecartes, total = selection(PLAFOND_PJ)
    joints = [os.path.basename(f) for f in chemins]
    tech = lignes_techniques()
    alertes = r.get('alertes', [])

    t = ['Bonjour,', '',
         'Voici le dossier de fabrication de votre Boîte à Bivouac, généré à partir de vos cotes. Il est offert : '
         'vous pouvez fabriquer la Boîte vous-même ou le confier à l\'atelier de soudure de votre choix.',
         '', 'VOTRE BOÎTE'] + ['- ' + x for x in tech]
    t += ['', 'DANS CE MESSAGE'] + ['- %s : %s' % (f, description(f)) for f in joints]
    if ecartes:
        t += ['', 'Trop lourds pour partir par email (écrivez-nous, nous vous les ferons passer autrement) :']
        t += ['- ' + f for f in ecartes]
    if alertes:
        t += ['', 'POINTS À VÉRIFIER AVANT FABRICATION'] + ['- ' + x for x in alertes]
    t += ['', 'POUR UN ATELIER DE SOUDURE',
          'Transférez simplement ce message : le plan PDF suffit pour chiffrer et fabriquer, '
          'les fichiers STEP s\'ouvrent dans tous les logiciels de CAO.',
          '', 'À SAVOIR'] + ['- ' + x for x in A_SAVOIR]
    t += ['', 'Une question, ou envie que nous fabriquions la Boîte pour vous ? Répondez à cet email.',
          '', 'Bon bivouac !', 'Matthieu — La Boîte à Bivouac', SITE]
    msg.set_content('\n'.join(t))

    e = html.escape
    corps = para('Bonjour,')
    corps += para('Voici le dossier de fabrication de votre Boîte à Bivouac, généré à partir de vos cotes. '
                  'Il est <strong>offert</strong>&nbsp;: fabriquez la Boîte vous-même ou confiez-le à l\'atelier '
                  'de soudure de votre choix.')
    corps += para('<strong>Votre Boîte</strong><br>' + '<br>'.join(e(x) for x in tech),
                  'padding:12px 14px;background:#F5ECD8;border-radius:10px;font-size:15px;')
    corps += para('<strong>Dans ce message</strong>', 'margin-bottom:6px;')
    corps += liste('<strong>%s</strong> — %s' % (e(f), e(description(f))) for f in joints)
    if ecartes:
        corps += para('Trop lourds pour partir par email (écrivez-nous, nous vous les ferons passer '
                      'autrement)&nbsp;: ' + ', '.join(e(f) for f in ecartes), 'color:#B85438;')
    if alertes:
        corps += para('<strong>Points à vérifier avant fabrication</strong>', 'margin-bottom:6px;color:#B85438;')
        corps += liste(e(x) for x in alertes)
    corps += para('<strong>Pour un atelier de soudure</strong><br>Transférez simplement ce message&nbsp;: '
                  'le plan PDF suffit pour chiffrer et fabriquer, les fichiers STEP s\'ouvrent dans tous les '
                  'logiciels de CAO.')
    corps += para('<strong>À savoir</strong>', 'margin-bottom:6px;font-size:14px;')
    corps += ('<ul style="margin:0 0 14px;padding-left:20px;font-size:14px;color:#6A6A62;">'
              + ''.join('<li style="margin:0 0 4px;">%s</li>' % e(x) for x in A_SAVOIR) + '</ul>')
    corps += para('Une question, ou envie que nous fabriquions la Boîte pour vous&nbsp;? Répondez à cet email.')
    corps += para('Bon bivouac&nbsp;!<br>Matthieu — La Boîte à Bivouac', 'margin:0;')
    msg.add_alternative(gabarit('Votre dossier de fabrication', corps), subtype='html')

    attacher(msg, chemins)
    return msg, total


def message_excuse():
    """Génération en échec : on prévient le demandeur sans l'accabler de détails techniques."""
    msg = EmailMessage()
    msg['Subject'] = 'Votre dossier de fabrication La Boîte à Bivouac — un contretemps'
    msg['From'] = 'La Boîte à Bivouac <%s>' % expediteur
    msg['To'] = demandeur
    msg['Reply-To'] = CONTACT
    msg.set_content('Bonjour,\n\nLa génération automatique de votre dossier de fabrication n\'a pas abouti '
                    '(réf. %s). Nous avons été prévenus et nous revenons vers vous rapidement, par email, '
                    'avec votre dossier ou une solution pour vos cotes.\n\nMerci de votre patience,\n'
                    'Matthieu — La Boîte à Bivouac\n%s\n' % (reference, SITE))
    corps = (para('Bonjour,')
             + para('La génération automatique de votre dossier de fabrication n\'a pas abouti (réf. %s). '
                    'Nous avons été prévenus et nous revenons vers vous rapidement, par email, avec votre '
                    'dossier ou une solution pour vos cotes.' % html.escape(reference))
             + para('Merci de votre patience,<br>Matthieu — La Boîte à Bivouac', 'margin:0;'))
    msg.add_alternative(gabarit('Un contretemps sur votre dossier', corps), subtype='html')
    return msg, 0


def message_interne(avec_pieces, note_demandeur=''):
    """Message pour Matthieu : le dossier atelier (comme avant) ou une simple notification."""
    libelle = {'ok': 'Dossier atelier prêt', 'a_verifier': 'Dossier atelier À VÉRIFIER',
               'erreur': 'ÉCHEC du dossier atelier'}[statut_code]
    if demandeur and statut_code != 'erreur':
        libelle = {'ok': 'Dossier offert envoyé', 'a_verifier': 'Dossier offert envoyé (À VÉRIFIER)'}[statut_code]
    lignes = ['%s — réf. %s' % (libelle, reference), '']
    if note_demandeur:
        lignes += [note_demandeur, '']
    lignes += lignes_techniques()
    for titre, cle in (('ERREURS', 'erreurs'), ('À VÉRIFIER', 'alertes')):
        if r.get(cle):
            lignes += ['', titre + ' :'] + ['- ' + x for x in r[cle]]
    if not demandeur:
        lignes += ['', 'Avant-projet généré automatiquement depuis le configurateur : à relire avant envoi à l\'atelier.']
    if os.environ.get('RUN_URL'):
        lignes.append('Exécution et fichiers : ' + os.environ['RUN_URL'])

    msg = EmailMessage()
    msg['Subject'] = '[Boîte à Bivouac] %s — %s' % (libelle, reference)
    msg['From'] = expediteur
    msg['To'] = interne
    if demandeur:
        msg['Reply-To'] = demandeur
    msg.set_content('\n'.join(lignes))
    total = 0
    if avec_pieces:
        chemins, _, total = selection(20e6)
        attacher(msg, chemins)
    return msg, total


# ------------------------------------------------------------------ envoi

if demandeur:
    if statut_code == 'erreur':
        messages = [message_excuse(),
                    message_interne(True, 'Demandeur prévenu de l\'échec : %s — à recontacter.' % demandeur)]
    else:
        messages = [message_demandeur(), message_interne(False, 'Envoyé à : %s' % demandeur)]
else:
    messages = [message_interne(True)]

if os.environ.get('ESSAI_A_BLANC'):
    for msg, total in messages:
        print('=' * 70)
        print('À :', msg['To'], '| Objet :', msg['Subject'])
        print(msg.get_body(preferencelist=('plain',)).get_content())
        print('Pièces jointes :', [p.get_filename() for p in msg.iter_attachments()], '%.1f Mo' % (total / 1e6))
        if os.environ.get('ESSAI_HTML'):
            open(os.environ['ESSAI_HTML'], 'w', encoding='utf-8').write(
                msg.get_body(preferencelist=('html',)).get_content() if msg.get_body(preferencelist=('html',)) else '')
    sys.exit(0)

port = int(os.environ.get('SMTP_PORT', '465'))
ctx = ssl.create_default_context()


def connexion():
    if port == 465:
        s = smtplib.SMTP_SSL(os.environ['SMTP_HOST'], port, context=ctx, timeout=60)
    else:
        s = smtplib.SMTP(os.environ['SMTP_HOST'], port, timeout=60)
        s.starttls(context=ctx)
    s.login(os.environ['SMTP_USER'], os.environ['SMTP_PASSWORD'])
    return s


echecs = 0
for msg, total in messages:
    # Une connexion par message : l'échec de l'un ne doit pas emporter l'autre.
    try:
        with connexion() as s:
            s.send_message(msg)
        # L'objet seul : jamais l'adresse du demandeur dans les journaux GitHub.
        print('E-mail envoyé :', msg['Subject'], '(%.1f Mo de pièces jointes)' % (total / 1e6))
    except Exception as ex:
        echecs += 1
        print('Échec d\'envoi :', msg['Subject'], '—', type(ex).__name__, file=sys.stderr)
sys.exit(1 if echecs else 0)
