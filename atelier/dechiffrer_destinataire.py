# -*- coding: utf-8 -*-
"""La Boîte à Bivouac — déchiffre l'adresse du demandeur d'un dossier offert.

Pendant de dgChiffrerEmail() (public/lib/dossier-gratuit.php). Format reçu :
base64url( iv(16) | chiffré AES-256-CBC | HMAC-SHA256(32) ), le HMAC couvrant iv + chiffré.
Les deux clés sont dérivées de la clé maîtresse (secret GitHub ATELIER_CLE_EMAIL, 32 octets en base64)
exactement comme côté PHP.

Python standard + l'outil openssl du runner : aucune dépendance à installer.

Usage : CLE_EMAIL=... python3 dechiffrer_destinataire.py config.json
Écrit l'adresse sur la sortie standard (rien si la configuration n'en porte pas).
Code de sortie 2 si le chiffré est présent mais illisible (clé absente, différente ou message altéré).
"""
import base64, hashlib, hmac, json, os, re, subprocess, sys


def b64url(texte):
    return base64.urlsafe_b64decode(texte + '=' * (-len(texte) % 4))


def dechiffrer(jeton, cle_b64):
    maitre = base64.b64decode(cle_b64, validate=True)
    if len(maitre) < 32:
        raise ValueError('clé trop courte')
    k_chiffrement = hmac.new(maitre, b'bab-email-chiffrement', hashlib.sha256).digest()
    k_auth = hmac.new(maitre, b'bab-email-authentification', hashlib.sha256).digest()
    brut = b64url(jeton)
    if len(brut) < 16 + 16 + 32:
        raise ValueError('chiffré trop court')
    iv, chiffre, mac = brut[:16], brut[16:-32], brut[-32:]
    if not hmac.compare_digest(hmac.new(k_auth, iv + chiffre, hashlib.sha256).digest(), mac):
        raise ValueError('authentification refusée')
    clair = subprocess.run(
        ['openssl', 'enc', '-d', '-aes-256-cbc', '-K', k_chiffrement.hex(), '-iv', iv.hex()],
        input=chiffre, capture_output=True, check=True,
    ).stdout.decode('utf-8')
    if not re.fullmatch(r'[^@\s]+@[^@\s]+\.[^@\s]+', clair) or len(clair) > 160:
        raise ValueError('adresse invalide après déchiffrement')
    return clair


if __name__ == '__main__':
    cfg = json.load(open(sys.argv[1] if len(sys.argv) > 1 else 'config.json', encoding='utf-8'))
    jeton = cfg.get('destinataire_chiffre')
    if not jeton:
        sys.exit(0)
    try:
        print(dechiffrer(jeton, os.environ.get('CLE_EMAIL', '')))
    except Exception as e:  # le message ne contient jamais l'adresse
        print('Déchiffrement impossible : %s' % e, file=sys.stderr)
        sys.exit(2)
