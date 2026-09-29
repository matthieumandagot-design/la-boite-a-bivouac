# -*- coding: utf-8 -*-
# La Boîte à Bivouac — générateur automatique du dossier atelier (étape 1 : construction du modèle)
# Lancement : BIVOUAC_CONFIG=config.json BIVOUAC_OUT=dossier/ xvfb-run freecad generer_boite.py
# Entrée (JSON) : reference, longueur_int_cm, largeur_int_cm, hauteur_ridelle_cm, hauteur_boite_cm, [marque, modele]
# La Boîte à Bivouac — squelette V1.5 (FreeCAD 1.1, mode GUI)
# Armature seule (pas de bois) : cornières percées pour riveter les panneaux, cadres de portes et de plateau
# en cornières d'onglet, charnières piano, butées anti-ouverture vers l'intérieur, platines de verrou.
import os, sys, time, json, math, re, traceback
CFG_PATH = os.environ.get('BIVOUAC_CONFIG', 'config.json')
OUT = os.environ.get('BIVOUAC_OUT', 'dossier')
os.makedirs(OUT, exist_ok=True)
LOG = open(os.path.join(OUT, 'journal_generation.txt'), 'w', encoding='utf-8')
def log(*a):
    LOG.write(' '.join(str(x) for x in a) + '\n'); LOG.flush()
RAPPORT = {'statut': 'erreur', 'erreurs': [], 'alertes': []}
def fin(code):
    json.dump(RAPPORT, open(os.path.join(OUT, 'rapport_generation.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
    LOG.close()
    os._exit(code)

# ---------------------------------------------------------------- configuration client -> paramètres
try:
    cfg = json.load(open(CFG_PATH, encoding='utf-8'))
except Exception as e:
    RAPPORT['erreurs'].append('Configuration illisible : %s' % e); fin(2)
def nombre(cle):
    try:
        return float(str(cfg.get(cle)).replace(',', '.'))
    except Exception:
        RAPPORT['erreurs'].append('Valeur manquante ou invalide : %s' % cle); return None
ref = re.sub(r'[^A-Za-z0-9_-]', '-', str(cfg.get('reference', 'sans-reference')))[:40]
Li, li, Hr, Hb = (nombre(k) for k in ('longueur_int_cm', 'largeur_int_cm', 'hauteur_ridelle_cm', 'hauteur_boite_cm'))
if RAPPORT['erreurs']:
    fin(2)
TUBE, JEU, JEU_CH = 30, 3, 6
LONG = int(round(Li * 10)) - 20           # 1 cm de jeu de chaque côté (règle du configurateur)
LARG = int(round(li * 10)) - 20
HAUT = int(round(Hb * 10))
HPLAT = int(round(Hr * 10)) + 10          # bas du plateau : 1 cm au-dessus de la ridelle avant
LGB = LARG + 300                          # barres de toit : 150 mm de débord de chaque côté
ENTR = min(980, int((LONG - 240) // 10) * 10)
HPL = HAUT - TUBE - HPLAT - JEU - JEU_CH  # hauteur du cadre de plateau
bornes = [('Longueur de boîte', LONG, 900, 2600), ('Largeur de boîte', LARG, 700, 1400),
          ('Hauteur de boîte', HAUT, 600, 1200), ('Hauteur de ridelle', int(round(Hr * 10)), 170, 600)]
for nom_, v, vmin, vmax in bornes:
    if not vmin <= v <= vmax:
        RAPPORT['erreurs'].append('%s hors plage : %d mm (autorisé de %d à %d mm)' % (nom_, v, vmin, vmax))
if HPL < 150:
    RAPPORT['erreurs'].append('Plateau trop petit : %d mm de haut (150 mm minimum). Boîte trop basse pour cette ridelle.' % HPL)
if ENTR < 400:
    RAPPORT['erreurs'].append('Boîte trop courte pour 2 barres de toit (entraxe %d mm).' % ENTR)
RAPPORT.update({'reference': ref, 'remorque': ('%s %s' % (cfg.get('marque', ''), cfg.get('modele', ''))).strip(),
                'entree': {'longueur_int_cm': Li, 'largeur_int_cm': li, 'hauteur_ridelle_cm': Hr, 'hauteur_boite_cm': Hb},
                'boite_mm': {'longueur': LONG, 'largeur': LARG, 'hauteur': HAUT, 'bas_plateau': HPLAT,
                             'hauteur_plateau': HPL, 'entraxe_barres': ENTR, 'longueur_barres': LGB}})
if RAPPORT['erreurs']:
    fin(2)
if cfg.get('ridelle_estimee'):
    RAPPORT['alertes'].append('Hauteur de ridelle estimée (absente de la fiche remorque) : la faire mesurer avant fabrication.')
NOM = 'Boite_%s_%dx%dx%d' % (ref, LONG, LARG, HAUT)
RAPPORT['fichier'] = NOM
log('Configuration', json.dumps(RAPPORT['boite_mm']))

try:
    import FreeCAD as App, FreeCADGui as Gui, Part, Draft, TechDraw
    from PySide import QtWidgets
    def pump(n=10):
        for _ in range(n):
            QtWidgets.QApplication.processEvents(); time.sleep(0.05)

    FILE = os.path.join(os.path.abspath(OUT), NOM + '.FCStd')
    doc = App.newDocument('Squelette')
    doc.Label = NOM
    doc.CreatedBy = 'La Boîte à Bivouac'; doc.Company = 'La Boîte à Bivouac'
    doc.Comment = "Généré automatiquement depuis le configurateur, réf. %s. Avant-projet non validé pour fabrication." % ref

    # ================================================================ Paramètres
    P = doc.addObject('Spreadsheet::Sheet', 'Parametres'); P.Label = 'Parametres'
    params = [
        ('Longueur',     str(LONG), 'mm',     "Longueur hors tout (sens de la remorque)"),
        ('Largeur',      str(LARG), 'mm',     "Largeur hors tout"),
        ('Hauteur',      str(HAUT), 'mm',     "Hauteur hors tout, sans les barres de toit"),
        ('Tube',         '30',   'mm',     "Côté des tubes = aile des cornières et des T"),
        ('Ep',           '2',    'mm',     "Épaisseur des tubes"),
        ('EpCorniere',   '3',    'mm',     "Épaisseur des cornières"),
        ('EpT',          '3',    'mm',     "Épaisseur des profilés en T"),
        ('EpCote',       '12',   'mm',     "Épaisseur des panneaux bois des côtés et de l'avant"),
        ('EpToit',       '15',   'mm',     "Épaisseur du panneau bois de toit"),
        ('EpPlancher',   '15',   'mm',     "Épaisseur du panneau bois de plancher"),
        ('HautPlateau',  str(HPLAT), 'mm',     "Bas du plateau, juste au-dessus de la ridelle avant (à mesurer)"),
        ('Entraxe',      str(ENTR), 'mm',     "Entraxe des 2 barres de toit (centrées)"),
        ('LgBarre',      str(LGB), 'mm',     "Longueur des barres de toit"),
        ('Rehausse',     '60',   'mm',     "Hauteur des rehausses sous les barres"),
        ('Jeu',          '3',    'mm',     "Jeu autour des ouvrants (hors côté charnière)"),
        ('JeuCharniere', '6',    'mm',     "Jeu côté charnière = Ø du nœud ; ouvrants en retrait de la moitié"),
        ('DiamRivet',    '5',    'mm',     "Diamètre des trous de rivets (rivet aveugle Ø4,8)"),
        ('PasRivets',    '150',  'mm',     "Pas maximal entre rivets"),
        ('MargeRivets',  '40',   'mm',     "Distance du premier rivet au bout de la zone"),
        ('Densite',      '7.93', 'kg/dm³', "Inox 304L = 7.93 ; acier S235 = 7.85"),
        ('AnglePorteG',  '0',    '°',      "Ouverture porte gauche (visualisation ; > 0 rentre ses verrous)"),
        ('AnglePorteD',  '0',    '°',      "Ouverture porte droite (visualisation ; à ouvrir en premier)"),
        ('AnglePlateau', '0',    '°',      "Ouverture du plateau avant (visualisation, 90 = à plat)"),
    ]
    for c, t in zip('ABCD', ('Paramètre', 'Valeur', 'Unité', 'Description')):
        P.set(c + '1', t)
    for i, (alias, val, unit, desc) in enumerate(params, start=2):
        P.set('A%d' % i, alias); P.set('B%d' % i, val); P.setAlias('B%d' % i, alias)
        P.set('C%d' % i, unit); P.set('D%d' % i, desc)
    last = 1 + len(params)
    P.setStyle('A1:D1', 'bold')
    P.setBackground('A1:D1', (0.118, 0.227, 0.157)); P.setForeground('A1:D1', (1, 1, 1))
    P.setBackground('B2:B%d' % last, (1.0, 0.95, 0.75))
    P.setColumnWidth('A', 115); P.setColumnWidth('B', 70); P.setColumnWidth('C', 60); P.setColumnWidth('D', 420)
    doc.recompute()

    S = 'Parametres.'
    L, W, H, T, E = S + 'Longueur', S + 'Largeur', S + 'Hauteur', S + 'Tube', S + 'Ep'
    EC, ETT, ECT, ETO, EPL = S + 'EpCorniere', S + 'EpT', S + 'EpCote', S + 'EpToit', S + 'EpPlancher'
    HPT, ENT, LB, RH, J = S + 'HautPlateau', S + 'Entraxe', S + 'LgBarre', S + 'Rehausse', S + 'Jeu'
    DR, PR, MR = S + 'DiamRivet', S + 'PasRivets', S + 'MargeRivets'
    APG, APD, APL = S + 'AnglePorteG', S + 'AnglePorteD', S + 'AnglePlateau'
    G = S + 'JeuCharniere'; RC = '%s / 2' % G

    C_TUBE = (0.62, 0.66, 0.70); C_MONT = (0.45, 0.52, 0.58); C_CORN = (0.33, 0.58, 0.72)
    C_APP = (0.85, 0.45, 0.25); C_T = (0.50, 0.62, 0.48); C_OR = (0.91, 0.706, 0.157)
    C_CADRE = (0.28, 0.30, 0.34); C_CHAR = (0.78, 0.79, 0.82); C_BUT = (0.80, 0.20, 0.20)

    # ================================================================ Outils de construction
    def hide(*objs):
        for o in objs:
            o.Visibility = False

    def place(obj, x, y, z):
        obj.setExpression('.Placement.Base.x', x)
        obj.setExpression('.Placement.Base.y', y)
        obj.setExpression('.Placement.Base.z', z)

    def bx(name, dx, dy, dz, x, y, z):
        b = doc.addObject('Part::Box', name)
        b.setExpression('Length', dx); b.setExpression('Width', dy); b.setExpression('Height', dz)
        place(b, x, y, z)
        return b

    def nb_trous(a, b):
        s = '((%s) - (%s) - 2 * %s)' % (b, a, MR)
        return 'ceil(%s / %s) + 1' % (s, PR)

    ROT = {'X': App.Rotation(App.Vector(0, 1, 0), 90), 'Y': App.Rotation(App.Vector(1, 0, 0), -90), 'Z': App.Rotation()}
    def trous(name, dist, a, b, normal, start, epaisseur, centre, created):
        """Rangée de trous Ø DiamRivet répartis le long de 'dist' entre a+marge et b-marge.
        normal : axe du perçage ; start : coordonnée de départ du perçage (face - 1) ; centre : coordonnée sur le 3e axe."""
        cyl = doc.addObject('Part::Cylinder', name + '_c')
        cyl.setExpression('Radius', '%s / 2' % DR)
        cyl.setExpression('Height', '(%s) + 2' % epaisseur)
        cyl.Placement = App.Placement(App.Vector(), ROT[normal])
        third = [ax for ax in 'XYZ' if ax not in (dist, normal)][0]
        coords = {dist: '(%s) + %s' % (a, MR), normal: start, third: centre}
        place(cyl, coords['X'], coords['Y'], coords['Z'])
        unit = {'X': App.Vector(100, 0, 0), 'Y': App.Vector(0, 100, 0), 'Z': App.Vector(0, 0, 100)}[dist]
        arr = Draft.make_ortho_array(cyl, v_x=unit, v_y=App.Vector(0, 0, 0), v_z=App.Vector(0, 0, 0),
                                     n_x=2, n_y=1, n_z=1, use_link=False)
        arr.Label = name
        s = '((%s) - (%s) - 2 * %s)' % (b, a, MR)
        arr.setExpression('NumberX', nb_trous(a, b))
        arr.setExpression('.IntervalX.%s' % dist.lower(), '%s / ceil(%s / %s)' % (s, s, PR))
        hide(cyl, arr)
        created += [cyl, arr]
        return arr

    def trous_platine(name, xstart, ys, zs, created, rayon=None):
        cyls = []
        for i, yy in enumerate(ys):
            for k, zz in enumerate(zs):
                c = doc.addObject('Part::Cylinder', '%s_t%d%d' % (name, i, k))
                c.setExpression('Radius', rayon if rayon else '%s / 2' % DR); c.Height = 5
                c.Placement = App.Placement(App.Vector(), ROT['X'])
                place(c, xstart, yy, zz); hide(c)
                cyls.append(c); created.append(c)
        return cyls

    def percer(name, base, rangees, created):
        """Retourne l'objet final : base percée des rangées de trous (ou base seule)."""
        if not rangees:
            return base
        comp = doc.addObject('Part::Compound', name + '_trous'); comp.Links = rangees
        cut = doc.addObject('Part::Cut', name + '_perce'); cut.Base = base; cut.Tool = comp
        hide(comp, base)
        created += [comp, cut]
        return cut

    pieces = []          # pièces soudées (repère de débit)
    def finir(obj, label, rep, color, group, created, container=None):
        obj.Label = '%s — %s' % (rep, label)
        obj.addProperty('App::PropertyString', 'Repere', 'Fabrication', 'Repère de débit'); obj.Repere = rep
        obj.ViewObject.ShapeColor = color; obj.ViewObject.LineColor = (0.15, 0.15, 0.15)
        obj.Visibility = True
        if container is not None:
            for o in created:
                if o not in container.Group:
                    container.addObject(o)
        elif group is not None:
            group.addObject(obj)
        pieces.append(obj)
        return obj

    def angle_base(name, axis, length, x, y, z, legs, created):
        pos = {'X': x, 'Y': y, 'Z': z}; d = {}; di = {}; pi = {}
        for ax in 'XYZ':
            if ax == axis:
                d[ax] = length; di[ax] = '(%s) + 2' % length; pi[ax] = '(%s) - 1' % pos[ax]
            else:
                d[ax] = T; di[ax] = '%s - %s + 1' % (T, EC)
                pi[ax] = '(%s) + %s' % (pos[ax], EC) if legs[ax] == 'min' else '(%s) - 1' % pos[ax]
        ext = bx(name + '_ext', d['X'], d['Y'], d['Z'], x, y, z)
        inn = bx(name + '_int', di['X'], di['Y'], di['Z'], pi['X'], pi['Y'], pi['Z'])
        c = doc.addObject('Part::Cut', name + '_L'); c.Base = ext; c.Tool = inn
        hide(ext, inn)
        created += [ext, inn, c]
        return c

    def corniere(name, label, rep, axis, length, x, y, z, legs, rangees_def, color, group, container=None):
        created = []
        base = angle_base(name, axis, length, x, y, z, legs, created)
        rangees = [trous(name + '_r%d' % i, *rd, created) for i, rd in enumerate(rangees_def)]
        obj = percer(name, base, rangees, created)
        return finir(obj, label, rep, color, group, created, container)

    def tube(name, label, rep, axis, length, x, y, z, color, group):
        created = []
        ext = doc.addObject('Part::Box', name + '_ext'); inn = doc.addObject('Part::Box', name + '_int')
        k = '%s - 2 * %s' % (T, E)
        if axis == 'X':
            for p_, v in (('Length', length), ('Width', T), ('Height', T)): ext.setExpression(p_, v)
            for p_, v in (('Length', '(%s) + 2' % length), ('Width', k), ('Height', k)): inn.setExpression(p_, v)
            ix, iy, iz = '(%s) - 1' % x, '(%s) + %s' % (y, E), '(%s) + %s' % (z, E)
        elif axis == 'Y':
            for p_, v in (('Length', T), ('Width', length), ('Height', T)): ext.setExpression(p_, v)
            for p_, v in (('Length', k), ('Width', '(%s) + 2' % length), ('Height', k)): inn.setExpression(p_, v)
            ix, iy, iz = '(%s) + %s' % (x, E), '(%s) - 1' % y, '(%s) + %s' % (z, E)
        else:
            for p_, v in (('Length', T), ('Width', T), ('Height', length)): ext.setExpression(p_, v)
            for p_, v in (('Length', k), ('Width', k), ('Height', '(%s) + 2' % length)): inn.setExpression(p_, v)
            ix, iy, iz = '(%s) + %s' % (x, E), '(%s) + %s' % (y, E), '(%s) - 1' % z
        place(ext, x, y, z); place(inn, ix, iy, iz)
        c = doc.addObject('Part::Cut', name); c.Base = ext; c.Tool = inn; hide(ext, inn)
        created += [ext, inn, c]
        return finir(c, label, rep, color, group, created)

    def plat(name, label, rep, dx, dy, dz, x, y, z, rangees_def, color, group, container=None, platine=None, extra_trous=None):
        created = []
        b = bx(name + '_b', dx, dy, dz, x, y, z); created.append(b)
        rangees = [trous(name + '_r%d' % i, *rd, created) for i, rd in enumerate(rangees_def)]
        if platine is not None:
            rangees += trous_platine(name, platine[0], platine[1], platine[2], created, platine[3] if len(platine) > 3 else None)
        if extra_trous is not None:
            rangees += extra_trous(created)
        obj = percer(name, b, rangees, created)
        return finir(obj, label, rep, color, group, created, container)

    root = doc.addObject('App::DocumentObjectGroup', 'Squelette'); root.Label = 'Squelette soudé'
    def grp(n, lab, parent=root):
        g = doc.addObject('App::DocumentObjectGroup', n); g.Label = lab; parent.addObject(g); return g
    gA = grp('G_Cornieres', '1 · Cornières longitudinales')
    gC = grp('G_Montants', '2 · Montants')
    gB = grp('G_Traverses', '3 · Traverses')
    gAP = grp('G_Appuis', '4 · Cornières d\'appui des panneaux')
    gBut = grp('G_Butees', '5 · Butées')
    gTo = grp('G_Toit', '6 · Barres de toit')

    # ================================================================ Repères géométriques
    ZT = '%s - %s' % (H, T); YR = '%s - %s' % (W, T); XR = '%s - %s' % (L, T)
    XB1 = '%s / 2 - %s / 2 - %s / 2' % (L, ENT, T)
    XB2 = '%s / 2 + %s / 2 - %s / 2' % (L, ENT, T)
    XC = '%s / 2 - %s / 2' % (L, T)                           # montant central
    XW1 = '%s + (%s - %s) / 2' % (XC, T, ETT)                 # face avant de l'âme du T
    XW2 = '%s + (%s + %s) / 2' % (XC, T, ETT)                 # face arrière de l'âme du T
    XR1 = '%s + (%s - %s) / 4' % (XC, T, ETT)                 # rangée de rivets 1 sur l'aile du T
    XR2 = '%s + %s - (%s - %s) / 4' % (XC, T, T, ETT)         # rangée de rivets 2
    BAND = '(%s + %s) / 2' % (T, EC)                          # milieu de la zone recouverte par une aile
    YPT = '(%s + %s + %s) / 2' % (EC, ECT, T)                 # rivets toit/plancher dans les ailes horizontales
    NP = 'Parametres.EpCorniere + 2'                          # épaisseur à percer (cornière)
    LA = '%s - 2 * %s' % (L, T)
    LT = '%s - 2 * %s' % (W, T)

    # ---------------------------------------------------------------- A — cornières longitudinales percées
    for nm, lab, y, z, ly, lz in (('CorniereBasG', 'Cornière basse gauche', '0', '0', 'min', 'min'),
                                  ('CorniereBasD', 'Cornière basse droite', YR, '0', 'max', 'min'),
                                  ('CorniereHautG', 'Cornière haute gauche', '0', ZT, 'min', 'max'),
                                  ('CorniereHautD', 'Cornière haute droite', YR, ZT, 'max', 'max')):
        yv = '-1' if ly == 'min' else '%s - %s - 1' % (W, EC)             # perçage aile verticale (normale Y)
        zc = BAND if lz == 'min' else '%s - %s' % (H, BAND)                # hauteur des rivets panneaux de côté
        zh = '-1' if lz == 'min' else '%s - %s - 1' % (H, EC)             # perçage aile horizontale (normale Z)
        yc = YPT if ly == 'min' else '%s - %s' % (W, YPT)
        rangs = [('X', '%s + %s' % (T, EC), XW1, 'Y', yv, EC, zc),         # panneau de côté avant
                 ('X', XW2, '%s - %s - %s' % (L, T, EC), 'Y', yv, EC, zc), # panneau de côté arrière
                 ('X', '%s + %s' % (T, EC), '%s - %s - %s' % (L, T, EC), 'Z', zh, EC, yc)]  # toit ou plancher
        corniere(nm, lab, 'A', 'X', LA, T, y, z, {'Y': ly, 'Z': lz}, rangs, C_CORN, gA)

    # ---------------------------------------------------------------- C — montants d'angle (tubes)
    for nm, lab, x, y in (('MontantAvG', 'Montant avant gauche', '0', '0'), ('MontantAvD', 'Montant avant droit', '0', YR),
                          ('MontantArG', 'Montant arrière gauche', XR, '0'), ('MontantArD', 'Montant arrière droit', XR, YR)):
        tube(nm, lab, 'C', 'Z', H, x, y, '0', C_MONT, gC)

    # ---------------------------------------------------------------- D — montant central en T, aile au ras extérieur
    ZW0 = '%s + %s' % (EC, EPL); LWD = '%s - 2 * %s - %s - %s' % (H, EC, EPL, ETO)
    for nm, lab, yf, yw, ystart in (('MontantCentralG', 'Montant central gauche (T)', '0', ETT, '-1'),
                                    ('MontantCentralD', 'Montant central droit (T)', '%s - %s' % (W, ETT), '%s - %s' % (W, T), '%s - %s - 1' % (W, ETT))):
        created = []
        fl = bx(nm + '_aile', T, ETT, '%s - 2 * %s' % (H, T), XC, yf, T)
        we = bx(nm + '_ame', ETT, '%s - %s' % (T, ETT), LWD, XW1, yw, ZW0)
        fu = doc.addObject('Part::MultiFuse', nm + '_T'); fu.Shapes = [fl, we]; hide(fl, we)
        created += [fl, we, fu]
        rangs = [trous(nm + '_r0', 'Z', T, '%s - %s' % (H, T), 'Y', ystart, ETT, XR1, created),
                 trous(nm + '_r1', 'Z', T, '%s - %s' % (H, T), 'Y', ystart, ETT, XR2, created)]
        finir(percer(nm, fu, rangs, created), lab, 'D', C_T, gC, created)

    # ---------------------------------------------------------------- B — traverses d'extrémité (tubes)
    for zn, z in (('Bas', '0'), ('Haut', ZT)):
        if zn == 'Bas':
            tube('Traverse%sAv' % zn, 'Traverse %s avant' % zn.lower(), 'B', 'Y', LT, '0', T, z, C_TUBE, gB)
        tube('Traverse%sAr' % zn, 'Traverse %s arrière' % zn.lower(), 'B', 'Y', LT, XR, T, z, C_TUBE, gB)
    # B2 — traverse haute avant, 4 trous Ø5 pour les gâches coudées des loquets du plateau
    YLQ = ('%s + %s + 15.5' % (T, J), '%s - %s - %s - 15.5' % (W, T, J))      # axes des loquets (milieu des montants du plateau)
    b2 = tube('TraverseHautAv', 'Traverse haute avant', 'B2', 'Y', LT, '0', T, ZT, C_TUBE, gB)
    b2.Repere = 'B2'
    created_b2 = []
    cyl_b2 = []
    for i, yl in enumerate(YLQ):
        for k, xx in enumerate(('8', '20')):
            c = doc.addObject('Part::Cylinder', 'TraverseHautAv_t%d%d' % (i, k)); c.setExpression('Radius', '%s / 2' % DR)
            c.setExpression('Height', '%s + 2' % E)
            place(c, xx, yl, '%s - %s - 1' % (H, E)); hide(c); cyl_b2.append(c)
    comp_b2 = doc.addObject('Part::Compound', 'TraverseHautAv_trous'); comp_b2.Links = cyl_b2; hide(comp_b2)
    cut_b2 = doc.addObject('Part::Cut', 'TraverseHautAv_perce'); cut_b2.Base = b2; cut_b2.Tool = comp_b2
    gB.removeObject(b2); pieces.remove(b2); b2.removeProperty('Repere'); hide(b2)
    finir(cut_b2, 'Traverse haute avant (4 trous Ø5 pour loquets)', 'B2', C_TUBE, gB, [])

    # ---------------------------------------------------------------- E — traverse de toit centrale en T (aile en haut)
    ZTOP = '%s - %s - %s' % (H, EC, ETO)
    created = []
    fl = bx('TraverseToit_aile', T, LT, ETT, XC, T, '%s - %s' % (ZTOP, ETT))
    we = bx('TraverseToit_ame', ETT, LT, '%s - %s' % (T, ETT), XW1, T, '%s - %s' % (ZTOP, T))
    fu = doc.addObject('Part::MultiFuse', 'TraverseToit_T'); fu.Shapes = [fl, we]; hide(fl, we)
    created += [fl, we, fu]
    rangs = [trous('TraverseToit_r0', 'Y', T, YR, 'Z', '%s - %s - 1' % (ZTOP, ETT), ETT, XR1, created),
             trous('TraverseToit_r1', 'Y', T, YR, 'Z', '%s - %s - 1' % (ZTOP, ETT), ETT, XR2, created)]
    finir(percer('TraverseToitCentrale', fu, rangs, created), 'Traverse de toit centrale (T)', 'E', C_T, gB, created)

    # ---------------------------------------------------------------- H — traverse de plateau (cornière, porte la charnière)
    corniere('TraversePlateau', 'Traverse de plateau (avant)', 'H', 'Y', LT, '0', T, '%s - %s' % (HPT, T),
             {'X': 'min', 'Z': 'max'},
             [('Y', '%s + %s' % (T, EC), '%s - %s - %s' % (W, T, EC), 'X', '-1', EC, '%s - %s' % (HPT, BAND))],
             C_CORN, gB)

    # ---------------------------------------------------------------- Cornières d'appui (là où les panneaux arrivent sur des tubes)
    XI_AV, XI_AR = T, '%s - 2 * %s' % (L, T)
    for nm, lab, x, y, lx, ly, xc in (('AppuiCoteAvG', 'Appui panneau de côté, avant gauche', XI_AV, '0', 'min', 'min', '%s + %s' % (T, BAND)),
                                      ('AppuiCoteAvD', 'Appui panneau de côté, avant droit', XI_AV, YR, 'min', 'max', '%s + %s' % (T, BAND)),
                                      ('AppuiCoteArG', 'Appui panneau de côté, arrière gauche', XI_AR, '0', 'max', 'min', '%s - %s - %s' % (L, T, BAND)),
                                      ('AppuiCoteArD', 'Appui panneau de côté, arrière droit', XI_AR, YR, 'max', 'max', '%s - %s - %s' % (L, T, BAND))):
        ys = '-1' if ly == 'min' else '%s - %s - 1' % (W, EC)
        corniere(nm, lab, 'I', 'Z', '%s - 2 * %s' % (H, T), x, y, T, {'X': lx, 'Y': ly},
                 [('Z', T, '%s - %s' % (H, T), 'Y', ys, EC, xc)], C_APP, gAP)
    for nm, lab, x, lx, xc in (('AppuiToitAv', 'Appui panneau de toit, avant', XI_AV, 'min', '%s + %s' % (T, BAND)),
                               ('AppuiToitAr', 'Appui panneau de toit, arrière', XI_AR, 'max', '%s - %s - %s' % (L, T, BAND))):
        corniere(nm, lab, 'J', 'Y', LT, x, T, ZT, {'X': lx, 'Z': 'max'},
                 [('Y', T, YR, 'Z', '%s - %s - 1' % (H, EC), EC, xc)], C_APP, gAP)
    for nm, lab, x, lx, xc in (('AppuiPlancherAv', 'Appui plancher, avant', XI_AV, 'min', '%s + %s' % (T, BAND)),
                               ('AppuiPlancherAr', 'Appui plancher, arrière', XI_AR, 'max', '%s - %s - %s' % (L, T, BAND))):
        corniere(nm, lab, 'K', 'Y', LT, x, T, '0', {'X': lx, 'Z': 'min'},
                 [('Y', T, YR, 'Z', '-1', EC, xc)], C_APP, gAP)
    LLV = '%s - 3 * %s' % (HPT, T)
    for nm, lab, y, ly, yc in (('AppuiAvantG', 'Appui panneau avant bas, gauche', T, 'min', '%s + %s' % (T, BAND)),
                               ('AppuiAvantD', 'Appui panneau avant bas, droit', '%s - 2 * %s' % (W, T), 'max', '%s - %s - %s' % (W, T, BAND))):
        corniere(nm, lab, 'L', 'Z', LLV, '0', y, '2 * %s' % T, {'X': 'min', 'Y': ly},
                 [('Z', '2 * %s' % T, '%s - %s' % (HPT, T), 'X', '-1', EC, yc)], C_APP, gAP)
    corniere('AppuiAvantBas', 'Appui panneau avant bas, en bas', 'M', 'Y', LT, '0', T, T, {'X': 'min', 'Z': 'min'},
             [('Y', '%s + %s' % (T, EC), '%s - %s - %s' % (W, T, EC), 'X', '-1', EC, '%s + %s' % (T, BAND))], C_APP, gAP)

    # ---------------------------------------------------------------- F — rehausses, G — barres de toit
    YB = '(%s - %s) / 2' % (W, LB)
    for i, xb in enumerate((XB1, XB2), start=1):
        tube('RehausseB%dG' % i, 'Rehausse barre %d gauche' % i, 'F', 'Z', RH, xb, '0', H, C_OR, gTo)
        tube('RehausseB%dD' % i, 'Rehausse barre %d droite' % i, 'F', 'Z', RH, xb, YR, H, C_OR, gTo)
        tube('BarreToit%d' % i, 'Barre de toit %d' % i, 'G', 'Y', LB, xb, YB, '%s + %s' % (H, RH), C_OR, gTo)

    # ---------------------------------------------------------------- R — butées (portes et plateau ne peuvent pas rentrer)
    ZB0 = '%s + %s' % (EC, EPL); ZB1 = '%s + 20' % T                # butées basses : de la face du plancher à 20 mm au-dessus de la traverse
    ZH0 = '%s - %s - 20' % (H, T); ZH1 = '%s - %s - %s' % (H, EC, ETO)
    XBA = '%s - %s - %s - 3' % (L, T, EC)                           # derrière l'aile verticale des cornières d'appui arrière
    for nm, lab, y, z0, z1 in (('ButeeBasG', 'Butée porte gauche, en bas', '%s / 2 - 120' % W, ZB0, ZB1),
                               ('ButeeHautG', 'Butée porte gauche, en haut', '%s / 2 - 120' % W, ZH0, ZH1)):
        plat(nm, lab, 'R', '3', '40', '(%s) - (%s)' % (z1, z0), XBA, y, z0, [], C_BUT, gBut)
    for nm, lab, y in (('ButeePlateauG', 'Butée plateau, gauche', '130'), ('ButeePlateauD', 'Butée plateau, droite', '%s - 170' % W)):
        plat(nm, lab, 'R', '3', '40', '(%s) - (%s)' % (ZH1, ZH0), '%s + %s' % (T, EC), y, ZH0, [], C_BUT, gBut)

    # Y1 — pattes d'attache des chaînes du plateau (soudées derrière la cornière d'appui de toit avant)
    ZCH = '%s - 37' % ZH1                                               # hauteur du trou d'attache
    for nm, lab, y0 in (('PatteChaineG', 'Patte de chaîne caisse, gauche', '50'), ('PatteChaineD', 'Patte de chaîne caisse, droite', '%s - 90' % W)):
        def trc(created, y0=y0, nm=nm):
            c = doc.addObject('Part::Cylinder', nm + '_trou'); c.Radius = 5; c.Height = 5
            c.Placement = App.Placement(App.Vector(), ROT['X'])
            place(c, '%s + %s - 1' % (T, EC), '(%s) + 20' % y0, ZCH); hide(c); created.append(c)
            return [c]
        plat(nm, lab, 'Y1', '3', '40', '52', '%s + %s' % (T, EC), y0, '%s - 52' % ZH1, [], C_BUT, gBut, extra_trous=trc)

    # U — gâches des verrous à ressort de la porte gauche (plats soudés derrière les cornières d'appui arrière)
    RCg = 'Parametres.JeuCharniere / 2'
    LPg = '(%s - 2 * %s - 2 * Parametres.JeuCharniere - %s) / 2' % (W, T, J)
    XV = '%s - %s - %s - 15' % (L, RCg, T)                              # axe des verrous (15 mm derrière le dos du cadre)
    YV = '%s + 2 * %s + (%s) - 20' % (T, RCg, LPg)                      # 20 mm avant le bord libre de la porte gauche
    XG0 = '%s - %s - %s - 27' % (L, T, EC)
    for nm, lab, z0 in (('GacheHaut', 'Gâche du verrou haut', '%s - %s - %s - 6' % (H, EC, ETO)),
                        ('GacheBas', 'Gâche du verrou bas', '%s + %s + 3' % (EC, EPL))):
        def tr(created, z0=z0, nm=nm):
            c = doc.addObject('Part::Cylinder', nm + '_trou'); c.Radius = 7; c.Height = 5
            place(c, XV, YV, '(%s) - 1' % z0); hide(c); created.append(c)
            return [c]
        plat(nm, lab, 'U', '27', '40', '3', XG0, '(%s) - 20' % YV, z0, [], C_BUT, gBut, extra_trous=tr)
    doc.recompute()

    # ================================================================ Ouvrants : cadres en cornières d'onglet sur charnières piano
    gO = doc.addObject('App::DocumentObjectGroup', 'Ouvrants'); gO.Label = 'Ouvrants (cadres, charnières, verrou)'
    achats = []
    def tag(o, typ):
        o.addProperty('App::PropertyString', 'Ouvrant', 'Fabrication', "Élément d'ouvrant"); o.Ouvrant = typ
        achats.append(o)
    def pivot(name, label, bxx, byy, bzz, axe, angle):
        p = doc.addObject('App::Part', name); p.Label = label
        place(p, bxx, byy, bzz)
        p.setExpression('.Placement.Rotation', 'create(<<rotation>>; create(<<vector>>; %s); %s)' % (axe, angle))
        gO.addObject(p); tag(p, 'Ouvrant')
        return p
    def boite(name, label, dx, dy, dz, x, y, z, color, parent):
        b = bx(name, dx, dy, dz, x, y, z); b.Label = label
        b.ViewObject.ShapeColor = color; b.ViewObject.LineColor = (0.25, 0.25, 0.25)
        parent.addObject(b); return b
    def noeud(name, label, length, x, y, z, axis, parent):
        c = doc.addObject('Part::Cylinder', name); c.Label = label; c.setExpression('Radius', RC)
        c.setExpression('Height', length)
        if axis == 'Y':
            c.Placement = App.Placement(App.Vector(), App.Rotation(App.Vector(1, 0, 0), -90))
        place(c, x, y, z)
        c.ViewObject.ShapeColor = C_CHAR; parent.addObject(c); tag(c, 'Charnière')
        return c

    def cadre(name, label, rep, cont, x0, y0, z0, ly, lz, xouter, created_extra=None):
        """Cadre plan en cornières d'onglet dans le plan YZ : profondeur T selon X, aile extérieure (EC) côté 'xouter'.
        x0 = x de la face arrière du cadre si xouter == 'max', sinon face avant. Trous dans l'aile extérieure."""
        created = []
        ext = bx(name + '_ext', T, ly, lz, x0, y0, z0)
        if xouter == 'max':   # aile extérieure en x0+T-EC..x0+T
            xa, xb, xs = '(%s) - 1' % x0, '(%s) - 1' % x0, '(%s) + %s - %s - 1' % (x0, T, EC)
        else:                 # aile extérieure en x0..x0+EC
            xa, xb, xs = '(%s) + %s' % (x0, EC), '(%s) - 1' % x0, '(%s) - 1' % x0
        a = bx(name + '_A', '%s - %s + 1' % (T, EC), '(%s) - 2 * %s' % (ly, EC), '(%s) - 2 * %s' % (lz, EC),
               xa, '(%s) + %s' % (y0, EC), '(%s) + %s' % (z0, EC))
        b = bx(name + '_B', '%s + 2' % T, '(%s) - 2 * %s' % (ly, T), '(%s) - 2 * %s' % (lz, T),
               xb, '(%s) + %s' % (y0, T), '(%s) + %s' % (z0, T))
        c1 = doc.addObject('Part::Cut', name + '_c1'); c1.Base = ext; c1.Tool = a
        c2 = doc.addObject('Part::Cut', name + '_c2'); c2.Base = c1; c2.Tool = b
        hide(ext, a, b, c1)
        created += [ext, a, b, c1, c2]
        y1 = '(%s) + (%s)' % (y0, ly); z1 = '(%s) + (%s)' % (z0, lz)
        rangs = [trous(name + '_rb', 'Y', '(%s) + %s' % (y0, T), '(%s) - %s' % (y1, T), 'X', xs, EC, '(%s) + %s' % (z0, BAND), created),
                 trous(name + '_rh', 'Y', '(%s) + %s' % (y0, T), '(%s) - %s' % (y1, T), 'X', xs, EC, '(%s) - %s' % (z1, BAND), created),
                 trous(name + '_rg', 'Z', '(%s) + %s' % (z0, T), '(%s) - %s' % (z1, T), 'X', xs, EC, '(%s) + %s' % (y0, BAND), created),
                 trous(name + '_rd', 'Z', '(%s) + %s' % (z0, T), '(%s) - %s' % (z1, T), 'X', xs, EC, '(%s) - %s' % (y1, BAND), created)]
        obj = percer(name, c2, rangs, created)
        return finir(obj, label, rep, C_CADRE, None, created, cont)

    LP  = '(%s - 2 * %s - 2 * %s - %s) / 2' % (W, T, G, J)  # largeur d'un vantail
    HP  = '%s - 2 * %s - 2 * %s' % (H, T, J)                 # hauteur des vantaux
    LPL = '%s - 2 * %s - 2 * %s' % (W, T, J)                 # largeur du plateau
    HPL = '%s - %s - %s - %s - %s' % (H, T, HPT, J, G)       # hauteur du plateau
    ZP  = '%s + %s' % (T, J)
    XA  = '%s - %s' % (L, RC)                                # axe des charnières arrière : nœud au ras de la face, à l'intérieur
    YG  = '%s + %s' % (T, RC)
    YD  = '%s - %s - %s' % (W, T, RC)

    # Porte arrière gauche (repère local : origine sur l'axe de charnière ; l'intérieur de la boîte est vers -X)
    pg = pivot('PorteG', 'Porte arrière gauche', XA, YG, '0', '0; 0; 1', '-' + APG)
    cadre('CadrePorteG', 'Cadre de porte gauche', 'N', pg, '-%s' % T, RC, ZP, LP, HP, 'max')
    boite('PorteG_Aile', 'Charnière porte G, aile mobile (sur la tranche du cadre)', '17 - %s' % RC, '1.5', HP, '-17', '%s - 1.5' % RC, ZP, C_CHAR, pg)
    # V — platines des verrous à ressort, soudées au dos du cadre (montant libre + traverse)
    YB0 = '%s + (%s) - 40' % (RC, LP)
    for nm, lab, z0, dz, zt in (('PlatineVerrouHaut', 'Platine du verrou haut', '%s - 155' % H, '155 - %s - %s' % (T, J), ('%s - 140' % H, '%s - 70' % H)),
                                ('PlatineVerrouBas', 'Platine du verrou bas', ZP, '155 - %s - %s' % (T, J), ('70', '140'))):
        plat(nm, lab, 'V', '3', '40', dz, '-%s - 3' % T, YB0, z0, [], C_BUT, None, pg,
             platine=('-%s - 4' % T, ['%s + 7.5' % YB0, '%s + 32.5' % YB0], list(zt), '3.25'))
    # W — taquets de la porte droite, soudés au dos du montant libre de la porte gauche
    for nm, lab, z0 in (('TaquetHaut', 'Taquet de la porte droite, haut', '%s - 290' % H), ('TaquetBas', 'Taquet de la porte droite, bas', '250')):
        plat(nm, lab, 'W', '3', '(%s) + 60' % J, '40', '-%s - 3' % T, '%s + (%s) - 20' % (RC, LP), z0, [], C_BUT, None, pg)
    # Verrous à ressort Ø12 (achat) : rentrés dès que la porte gauche est ouverte
    RETR = '(%s > 0 ? 26 : 0)' % APG
    YVc = '%s + (%s) - 20' % (RC, LP)
    XVb = '-%s - 3' % T
    def verrou(nm, haut):
        sgn = '-' if haut else '+'
        zb = ('%s - 150' % H) if haut else '60'
        zpin = ('%s - %s - %s - 1 - 150 - %s' % (H, EC, ETO, RETR)) if haut else ('%s + %s + 1 + %s' % (EC, EPL, RETR))
        parts_ = []
        parts_.append(boite(nm + '_Base', 'Verrou à ressort, embase', '2.5', '35', '90', '%s - 2.5' % XVb, '(%s) - 17.5' % YVc, zb, C_CHAR, pg))
        for k, dz in enumerate(('0', '87.5')):
            parts_.append(boite(nm + '_Oreille%d' % k, 'Verrou à ressort, oreille', '17.5', '35', '2.5', '%s - 20' % XVb, '(%s) - 17.5' % YVc, '(%s) + %s' % (zb, dz), C_CHAR, pg))
        c = doc.addObject('Part::Cylinder', nm + '_Pene'); c.Label = 'Verrou à ressort, pêne Ø12'; c.Radius = 6; c.Height = 150
        place(c, '%s - 12' % XVb, YVc, zpin); c.ViewObject.ShapeColor = C_CHAR; pg.addObject(c)
        h = doc.addObject('Part::Cylinder', nm + '_Poignee'); h.Label = 'Verrou à ressort, poignée'; h.Radius = 5; h.Height = 35
        h.Placement = App.Placement(App.Vector(), App.Rotation(App.Vector(0, 1, 0), -90))
        place(h, '%s - 12' % XVb, YVc, '(%s) + 75' % zpin); h.ViewObject.ShapeColor = (0.1, 0.1, 0.1); pg.addObject(h)
        for p_ in parts_ + [c, h]:
            tag(p_, 'Verrou')
    verrou('VerrouHaut', True)
    verrou('VerrouBas', False)
    tag(boite('CharPorteG_Fixe', 'Charnière porte G, aile fixe (sur le montant)', '17 - %s' % RC, '1.5', HP,
              '%s - %s - 17' % (L, RC), T, ZP, C_CHAR, gO), 'Charnière')
    noeud('CharPorteG_Noeud', 'Charnière porte G, nœud', HP, XA, YG, ZP, 'Z', gO)

    # Porte arrière droite
    pd = pivot('PorteD', 'Porte arrière droite', XA, YD, '0', '0; 0; 1', APD)
    cadre('CadrePorteD', 'Cadre de porte droite', 'N', pd, '-%s' % T, '-%s - (%s)' % (RC, LP), ZP, LP, HP, 'max')
    boite('PorteD_Aile', 'Charnière porte D, aile mobile (sur la tranche du cadre)', '17 - %s' % RC, '1.5', HP, '-17', '-%s' % RC, ZP, C_CHAR, pd)
    # X1 — platine du coffre de verrou à clé (porte droite), X2 — platine de gâche (porte gauche)
    ZV = '%s / 2' % H
    YR0 = '-%s - (%s)' % (RC, LP)                                       # bord libre de la porte droite (repère local)
    def trous_x(name, xs, pts, rayon, created):
        cyls = []
        for i, (yy, zz, rr) in enumerate(pts):
            c = doc.addObject('Part::Cylinder', '%s_t%d' % (name, i)); c.Radius = rr; c.Height = 5
            c.Placement = App.Placement(App.Vector(), ROT['X'])
            place(c, xs, yy, zz); hide(c); cyls.append(c); created.append(c)
        return cyls
    YCYL = '%s + 43.5' % YR0                                            # axe du cylindre Ø23 (hors aile du cadre)
    plat('PlatineCoffre', 'Platine du coffre de verrou à clé', 'X1', '3', '80', '110', '-%s - 3' % T, YR0, '%s - 55' % ZV, [], C_BUT, None, pd,
         extra_trous=lambda cr: trous_x('PlatineCoffre', '-%s - 4' % T,
                                        [(YCYL, ZV, 12.5), ('%s - 20' % YCYL, '%s - 35' % ZV, 3.25), ('%s + 20' % YCYL, '%s - 35' % ZV, 3.25),
                                         ('%s - 20' % YCYL, '%s + 35' % ZV, 3.25), ('%s + 20' % YCYL, '%s + 35' % ZV, 3.25)], None, cr))
    RETRD = '(%s > 0 ? 47 : 0)' % APD
    for p_ in (boite('Coffre', 'Verrou à clé : coffre 90 x 60', '30', '60', '90', '-%s - 33' % T, '%s - 30' % YCYL, '%s - 45' % ZV, (0.72, 0.62, 0.40), pd),
               boite('Pene', 'Verrou à clé : pêne', '12', '60', '22', '-%s - 21' % T, '%s - 30 - 47 + %s' % (YCYL, RETRD), '%s - 11' % ZV, (0.85, 0.85, 0.88), pd)):
        tag(p_, 'Verrou')
    cy = doc.addObject('Part::Cylinder', 'Cylindre'); cy.Label = 'Verrou à clé : cylindre Ø23'; cy.Radius = 11.5
    cy.setExpression('Height', '%s + 2' % T)
    cy.Placement = App.Placement(App.Vector(), ROT['X'])
    place(cy, '-%s - 3' % T, YCYL, ZV); cy.ViewObject.ShapeColor = (0.85, 0.85, 0.88); pd.addObject(cy); tag(cy, 'Verrou')
    YG0 = '%s + (%s) - 40' % (RC, LP)                                   # platine de gâche sur la porte gauche (repère local)
    plat('PlatineGache', 'Platine de gâche du verrou à clé', 'X2', '3', '40', '110', '-%s - 3' % T, YG0, '%s - 55' % ZV, [], C_BUT, None, pg,
         extra_trous=lambda cr: trous_x('PlatineGache', '-%s - 4' % T,
                                        [('%s + 11' % YG0, '%s - 35' % ZV, 3.25), ('%s + 29' % YG0, '%s - 35' % ZV, 3.25),
                                         ('%s + 11' % YG0, '%s + 35' % ZV, 3.25), ('%s + 29' % YG0, '%s + 35' % ZV, 3.25)], None, cr))
    tag(boite('Gache', 'Verrou à clé : gâche 90 x 36', '30', '36', '90', '-%s - 33' % T, '%s + 2' % YG0, '%s - 45' % ZV, (0.72, 0.62, 0.40), pg), 'Verrou')
    tag(boite('CharPorteD_Fixe', 'Charnière porte D, aile fixe (sur le montant)', '17 - %s' % RC, '1.5', HP,
              '%s - %s - 17' % (L, RC), '%s - %s - 1.5' % (W, T), ZP, C_CHAR, gO), 'Charnière')
    noeud('CharPorteD_Noeud', 'Charnière porte D, nœud', HP, XA, YD, ZP, 'Z', gO)

    # Plateau basculant avant : axe au ras de la face avant, juste au-dessus de la traverse de plateau (l'intérieur est vers +X)
    ZA = '%s + %s' % (HPT, RC)
    pl = pivot('Plateau', 'Plateau basculant avant', RC, '0', ZA, '0; 1; 0', '-' + APL)
    cadre('CadrePlateau', 'Cadre de plateau', 'O', pl, '0', ZP, RC, LPL, HPL, 'min')
    boite('Plateau_Aile', 'Charnière plateau, aile mobile (sous le cadre)', '17 - %s' % RC, LPL, '1.5', RC, ZP, '%s - 1.5' % RC, C_CHAR, pl)
    tag(boite('CharPlateau_Fixe', 'Charnière plateau, aile fixe (sur la traverse)', '17 - %s' % RC, LPL, '1.5', '2 * %s' % RC, ZP, HPT, C_CHAR, gO), 'Charnière')
    noeud('CharPlateau_Noeud', 'Charnière plateau, nœud', LPL, RC, ZP, ZA, 'Y', gO)

    # Loquets à levier cadenassables (achat) sur les montants du plateau, gâches coudées sur la traverse haute avant
    ZTOPP = '%s + %s' % (RC, HPL)                                       # haut du plateau (repère local)
    C_LQ = (0.80, 0.82, 0.86)
    for i, yl in enumerate(YLQ):
        for p_ in (boite('Loquet%d_Embase' % i, 'Loquet : embase', '2', '25', '80', '-2', '(%s) - 12.5' % yl, '%s - 95' % ZTOPP, C_LQ, pl),
                   boite('Loquet%d_Levier' % i, 'Loquet : levier', '12', '21', '60', '-14', '(%s) - 10.5' % yl, '%s - 75' % ZTOPP, C_LQ, pl),
                   boite('Loquet%d_Anse1' % i, 'Loquet : anse', '3', '3', '%s + 3 - (%s - 15)' % (ZTOPP, ZTOPP), '-8.5', '(%s) - 9' % yl, '%s - 15' % ZTOPP, C_LQ, pl),
                   boite('Loquet%d_Anse2' % i, 'Loquet : anse', '3', '3', '18', '-8.5', '(%s) + 6' % yl, '%s - 15' % ZTOPP, C_LQ, pl),
                   boite('Loquet%d_Barre' % i, 'Loquet : anse', '3', '18', '3', '-8.5', '(%s) - 9' % yl, '%s + 3' % ZTOPP, C_LQ, pl)):
            tag(p_, 'Loquet')
        # gâche coudée (achat) posée sur la traverse haute avant
        for p_ in (boite('GacheLoquet%d_Plat' % i, 'Gâche coudée : aile rivetée', '30', '20', '2', '-2', '(%s) - 10' % yl, H, C_LQ, gO),
                   boite('GacheLoquet%d_Aile' % i, 'Gâche coudée : aile verticale', '2', '20', '28', '-2', '(%s) - 10' % yl, '%s - 26' % H, C_LQ, gO)):
            tag(p_, 'Loquet')
        lev = doc.addObject('Part::Cylinder', 'GacheLoquet%d_Bord' % i); lev.Label = 'Gâche coudée : bord roulé'; lev.Radius = 3
        lev.setExpression('Height', '20')
        lev.Placement = App.Placement(App.Vector(), ROT['Y'])
        place(lev, '-5', '(%s) - 10' % yl, '%s - 23' % H); lev.ViewObject.ShapeColor = C_LQ; gO.addObject(lev); tag(lev, 'Loquet')

    # Y2 — pattes de chaîne sur le plateau (soudées au dos des montants, près du haut)
    for nm, lab, y0 in (('PatteChainePlG', 'Patte de chaîne plateau, gauche', ZP), ('PatteChainePlD', 'Patte de chaîne plateau, droite', '%s - %s - %s - 40' % (W, T, J))):
        def trp(created, y0=y0, nm=nm):
            c = doc.addObject('Part::Cylinder', nm + '_trou'); c.Radius = 5; c.Height = 5
            c.Placement = App.Placement(App.Vector(), ROT['X'])
            place(c, '%s - 1' % T, '(%s) + 27' % y0 if nm.endswith('G') else '(%s) + 13' % y0, '%s - 74' % ZTOPP); hide(c); created.append(c)
            return [c]
        plat(nm, lab, 'Y2', '3', '40', '45', T, y0, '%s - 97' % ZTOPP, [], C_BUT, None, pl, extra_trous=trp)

    # Chaînes (achat), représentées tendues entre les deux pattes, suivent l'angle du plateau
    XAN = '%s + %s + 6' % (T, EC)                                       # 3 mm derrière la patte de caisse
    for nm, ya, ye in (('ChaineG', '70', '%s + 27' % ZP), ('ChaineD', '%s - 70' % W, '%s - %s - %s - 40 + 13' % (W, T, J))):
        xl = '(%s + 6)' % T; zl = '(%s - 74)' % ZTOPP
        vx = '(%s + %s * cos(%s) - %s * sin(%s) - (%s))' % (RC, xl, APL, zl, APL, XAN)
        vy = '((%s) - (%s))' % (ye, ya)
        vz = '(%s + %s * sin(%s) + %s * cos(%s) - (%s))' % (ZA, xl, APL, zl, APL, ZCH)
        ln = 'sqrt(%s ^ 2 + %s ^ 2 + %s ^ 2)' % (vx, vy, vz)
        ch = doc.addObject('Part::Cylinder', nm); ch.Label = 'Chaîne du plateau'; ch.Radius = 3
        ch.setExpression('Height', ln)
        place(ch, XAN, ya, ZCH)
        ch.setExpression('.Placement.Rotation', 'create(<<rotation>>; create(<<vector>>; -%s; %s; 0); acos(%s / %s))' % (vy, vx, vz, ln))
        ch.ViewObject.ShapeColor = (0.25, 0.25, 0.25); gO.addObject(ch); tag(ch, 'Chaîne')
    doc.recompute()

    # ================================================================ Débit
    D = doc.addObject('Spreadsheet::Sheet', 'Debit'); D.Label = 'Debit'
    hdr = ['Repère', 'Désignation', 'Profil', 'Section (mm)', 'Ép. (mm)', 'Qté', 'Long. unit. (mm)', 'Coupes', 'Trous Ø/pièce', 'Long. tot. (m)', 'Masse (kg)']
    for c, t in zip('ABCDEFGHIJK', hdr):
        D.set(c + '1', t)
    n = nb_trous
    rows = [
        ('A', 'Cornière longitudinale', 'Cornière', T, EC, '4', LA, 'Droites',
         '%s + %s + %s' % (n('%s + %s' % (T, EC), XW1), n(XW2, '%s - %s - %s' % (L, T, EC)), n('%s + %s' % (T, EC), '%s - %s - %s' % (L, T, EC))), 'MasseLinC'),
        ('B', 'Traverse d\'extrémité', 'Tube carré', T, E, '3', LT, 'Droites', '0', 'MasseLinT'),
        ('B2', 'Traverse haute avant (4 trous Ø5, gâches de loquets)', 'Tube carré', T, E, '1', LT, 'Droites', '4', 'MasseLinT'),
        ('C', 'Montant d\'angle', 'Tube carré', T, E, '4', H, 'Droites', '0', 'MasseLinT'),
        ('D', 'Montant central, aile arasée à H - 2 x Tube', 'Profilé T', T, ETT, '2', LWD, 'Droites + encoche', '2 * (%s)' % n(T, '%s - %s' % (H, T)), 'MasseLinTe'),
        ('E', 'Traverse de toit centrale', 'Profilé T', T, ETT, '1', LT, 'Droites', '2 * (%s)' % n(T, YR), 'MasseLinTe'),
        ('F', 'Rehausse sous barre de toit', 'Tube carré', T, E, '4', RH, 'Droites', '0', 'MasseLinT'),
        ('G', 'Barre de toit transversale', 'Tube carré', T, E, '2', LB, 'Droites', '0', 'MasseLinT'),
        ('H', 'Traverse de plateau (porte la charnière)', 'Cornière', T, EC, '1', LT, 'Droites', n('%s + %s' % (T, EC), '%s - %s - %s' % (W, T, EC)), 'MasseLinC'),
        ('I', 'Appui panneau de côté, vertical', 'Cornière', T, EC, '4', '%s - 2 * %s' % (H, T), 'Droites', n(T, '%s - %s' % (H, T)), 'MasseLinC'),
        ('J', 'Appui panneau de toit (avant, arrière)', 'Cornière', T, EC, '2', LT, 'Droites', n(T, YR), 'MasseLinC'),
        ('K', 'Appui plancher (avant, arrière)', 'Cornière', T, EC, '2', LT, 'Droites', n(T, YR), 'MasseLinC'),
        ('L', 'Appui panneau avant bas, vertical', 'Cornière', T, EC, '2', LLV, 'Droites', n('2 * %s' % T, '%s - %s' % (HPT, T)), 'MasseLinC'),
        ('M', 'Appui panneau avant bas, horizontal', 'Cornière', T, EC, '1', LT, 'Droites', n('%s + %s' % (T, EC), '%s - %s - %s' % (W, T, EC)), 'MasseLinC'),
        ('N', 'Cadre de porte : traverse', 'Cornière', T, EC, '4', LP, 'Onglet 45°', n(T, '(%s) - %s' % (LP, T)), 'MasseLinC'),
        ('N', 'Cadre de porte : montant', 'Cornière', T, EC, '4', HP, 'Onglet 45°', n(T, '(%s) - %s' % (HP, T)), 'MasseLinC'),
        ('O', 'Cadre de plateau : traverse', 'Cornière', T, EC, '2', LPL, 'Onglet 45°', n(T, '(%s) - %s' % (LPL, T)), 'MasseLinC'),
        ('O', 'Cadre de plateau : montant', 'Cornière', T, EC, '2', HPL, 'Onglet 45°', n(T, '(%s) - %s' % (HPL, T)), 'MasseLinC'),
        ('R', 'Butée (porte gauche, plateau)', 'Plat 40 x 3', '40', '3', '4', '%s - %s - 20 - (%s + %s)' % (T, '0', EC, EPL), 'Droites', '0', 'MasseLinP'),
        ('U', 'Gâche de verrou, trou Ø14', 'Plat 40 x 3', '40', '3', '2', '27', 'Droites', '0', 'MasseLinP'),
        ('V', 'Platine de verrou à ressort, 4 trous Ø6,5', 'Plat 40 x 3', '40', '3', '2', '155 - %s - %s' % (T, J), 'Droites', '0', 'MasseLinP'),
        ('W', 'Taquet de la porte droite (sur porte gauche)', 'Plat 40 x 3', '40', '3', '2', '(%s) + 60' % J, 'Droites', '0', 'MasseLinP'),
        ('X1', 'Platine de coffre, verrou à clé (1 trou Ø25 + 4 Ø6,5)', 'Plat 80 x 3', '80', '3', '1', '110', 'Droites', '0', 'MasseLinP80'),
        ('X2', 'Platine de gâche, verrou à clé (4 trous Ø6,5)', 'Plat 40 x 3', '40', '3', '1', '110', 'Droites', '0', 'MasseLinP'),
        ('Y1', 'Patte de chaîne sur caisse (trou Ø10)', 'Plat 40 x 3', '40', '3', '2', '52', 'Droites', '0', 'MasseLinP'),
        ('Y2', 'Patte de chaîne sur plateau (trou Ø10)', 'Plat 40 x 3', '40', '3', '2', '45', 'Droites', '0', 'MasseLinP'),
    ]
    # longueur réelle des butées : hauteur ZB1 - ZB0 (identique en haut)
    for i_, r_ in enumerate(rows):
        if r_[0] == 'R':
            rows[i_] = r_[:6] + ('(%s) - (%s)' % (ZB1, ZB0),) + r_[7:]
    first = 2
    for r, (rep, des, prof, sec, ep, qty, lg, coupe, tr, ml) in enumerate(rows, start=first):
        D.set('A%d' % r, rep); D.set('B%d' % r, des); D.set('C%d' % r, prof)
        D.set('D%d' % r, '=' + sec); D.set('E%d' % r, '=' + ep)
        D.set('F%d' % r, qty); D.set('G%d' % r, '=round((%s) * 10) / 10' % lg); D.set('H%d' % r, coupe)
        D.set('I%d' % r, '=' + tr)
        D.set('J%d' % r, '=round(F%d * G%d / 10) / 100' % (r, r))
        D.set('K%d' % r, '=round(J%d * %s * 10) / 10' % (r, ml))
    lastp = first + len(rows) - 1
    groups = {'Tube carré': [], 'Cornière': [], 'Profilé T': [], 'Plat 40 x 3': [], 'Plat 80 x 3': []}
    for r, row in enumerate(rows, start=first):
        groups[row[2]].append(r)
    tr_ = lastp + 1
    for k, (lab, key) in enumerate((('Total tube carré', 'Tube carré'), ('Total cornière', 'Cornière'),
                                    ('Total profilé T', 'Profilé T'), ('Total plat', 'Plat 40 x 3'))):
        rr = tr_ + k; ids = groups[key] + (groups['Plat 80 x 3'] if key == 'Plat 40 x 3' else [])
        D.set('B%d' % rr, lab)
        D.set('F%d' % rr, '=' + ' + '.join('F%d' % i for i in ids))
        D.set('J%d' % rr, '=' + ' + '.join('J%d' % i for i in ids))
        D.set('K%d' % rr, '=round((' + ' + '.join('K%d' % i for i in ids) + ') * 10) / 10')
    tg = tr_ + 4
    D.set('B%d' % tg, 'Total général')
    D.set('F%d' % tg, '=sum(F%d:F%d)' % (tr_, tr_ + 3))
    D.set('I%d' % tg, '=' + ' + '.join('F%d * I%d' % (i, i) for i in range(first, lastp + 1)))
    D.set('J%d' % tg, '=sum(J%d:J%d)' % (tr_, tr_ + 3))
    D.set('K%d' % tg, '=round(sum(K%d:K%d) * 10) / 10' % (tr_, tr_ + 3))
    D.setAlias('I%d' % tg, 'TotalTrous')
    ml0 = tg + 2
    for k, (lab, alias, f) in enumerate((('Masse linéique tube (kg/m)', 'MasseLinT', '(%s ^ 2 - (%s - 2 * %s) ^ 2)' % (T, T, E)),
                                         ('Masse linéique cornière (kg/m)', 'MasseLinC', '(%s ^ 2 - (%s - %s) ^ 2)' % (T, T, EC)),
                                         ('Masse linéique profilé T (kg/m)', 'MasseLinTe', '(2 * %s * %s - %s ^ 2)' % (T, ETT, ETT)),
                                         ('Masse linéique plat 40 x 3 (kg/m)', 'MasseLinP', '(40 * 3)'),
                                         ('Masse linéique plat 80 x 3 (kg/m)', 'MasseLinP80', '(80 * 3)'))):
        rr = ml0 + k
        D.set('A%d' % rr, lab)
        D.set('G%d' % rr, '=round(%s * %s / 10) / 100' % (f, S + 'Densite')); D.setAlias('G%d' % rr, alias)
    D.set('A%d' % (ml0 + 6), "Inox soudé. Trous Ø DiamRivet pour rivets aveugles des panneaux bois. Onglets 45° sur les cadres d'ouvrants.")
    D.setStyle('A1:K1', 'bold'); D.setStyle('A%d:K%d' % (tr_, tg), 'bold')
    D.setBackground('A1:K1', (0.118, 0.227, 0.157)); D.setForeground('A1:K1', (1, 1, 1))
    D.setBackground('A%d:K%d' % (tg, tg), (0.93, 0.93, 0.93))
    for c, w in zip('ABCDEFGHIJK', (50, 270, 80, 75, 50, 35, 95, 105, 80, 80, 70)):
        D.setColumnWidth(c, w)

    # ================================================================ Achats (hors soudure)
    # longueur de chaîne : distance entre les pattes, plateau à 90°
    CHL = 'sqrt((%s - (%s) - (%s + %s + 6)) ^ 2 + 10 ^ 2 + (%s + %s + 6 - (%s - 37)) ^ 2)' % (
        RC, '(%s + %s - 74)' % (RC, HPL), T, EC, ZA, T, ZH1)
    O = doc.addObject('Spreadsheet::Sheet', 'Achats'); O.Label = 'Achats'
    for c, t in zip('ABCD', ('Article', 'Qté', 'Longueur (mm)', 'Remarque')):
        O.set(c + '1', t)
    for r, row in enumerate((('Charnière piano inox, portes', '2', '=round(%s)' % HP, 'Ouverte 40 mm, rivetée'),
                             ('Charnière piano inox, plateau', '1', '=round(%s)' % LPL, 'Ouverte 40 mm, rivetée'),
                             ('Rivet aveugle Ø4,8 tête large', '=Debit.TotalTrous', '', 'Hors fixation des charnières'),
                             ('Verrou à ressort Ø12 (porte gauche)', '2', '', 'Haut et bas, boulonné sur les platines V'),
                             ('Verrou à clé Thirard à bouton et cylindre', '1', '', 'Coffre 90 x 60, gâche 90 x 36, cylindre Ø23 x 45'),
                             ('Boulon M6 + écrou frein', '16', '', 'Verrous à ressort (8) et verrou à clé (8)'),
                             ('Loquet à levier cadenassable + gâche coudée', '2', '', 'Plateau, rivetés'),
                             ('Chaîne inox Ø5 + 2 maillons rapides', '2', '=round((%s) / 10) * 10 + 50' % CHL, 'Plateau tenu à l\'horizontale')), start=2):
        for c, v in zip('ABCD', row):
            O.set('%s%d' % (c, r), v)
    O.setStyle('A1:D1', 'bold'); O.setBackground('A1:D1', (0.118, 0.227, 0.157)); O.setForeground('A1:D1', (1, 1, 1))
    for c, w in zip('ABCD', (220, 50, 100, 200)):
        O.setColumnWidth(c, w)

    # ================================================================ Cotes
    C = doc.addObject('Spreadsheet::Sheet', 'Cotes'); C.Label = 'Cotes'
    C.set('A1', 'Cote de positionnement'); C.set('B1', 'Valeur'); C.set('C1', 'Unité')
    crow = [
        ('Encombrement : longueur', '=' + L),
        ('Encombrement : largeur (barres comprises)', '=max(%s; %s)' % (W, LB)),
        ('Encombrement : hauteur (barres comprises)', '=%s + %s + %s' % (H, RH, T)),
        ('Axe barre de toit 1 depuis la face avant', '=%s / 2 - %s / 2' % (L, ENT)),
        ('Axe barre de toit 2 depuis la face avant', '=%s / 2 + %s / 2' % (L, ENT)),
        ('Axe du montant central depuis la face avant', '=%s / 2' % L),
        ('Dessus de la traverse de plateau', '=' + HPT),
        ('Axe de la charnière du plateau', '=' + ZA),
        ('Portes et plateau : retrait de la face extérieure', '=' + RC),
        ('Jeu côté charnière (nœud Ø)', '=' + G),
        ('Axe des verrous : derrière la face arrière', '=%s + %s + 15' % (RC, T)),
        ('Axe des verrous : depuis le bord libre de la porte gauche', '20'),
        ('Verrou à clé : axe du cylindre depuis le bord libre porte droite', '43.5'),
        ('Verrou à clé : hauteur de l\'axe', '=%s / 2' % H),
        ('Rivets : trou Ø', '=' + DR),
        ('Rivets : pas maximal', '=' + PR),
        ('Rivets : premier trou depuis le bout de la zone', '=' + MR),
    ]
    for i, (lab, val) in enumerate(crow, start=2):
        C.set('A%d' % i, lab); C.set('B%d' % i, val); C.set('C%d' % i, 'mm')
    clast = 1 + len(crow)
    C.setStyle('A1:C1', 'bold'); C.setBackground('A1:C1', (0.118, 0.227, 0.157)); C.setForeground('A1:C1', (1, 1, 1))
    C.setColumnWidth('A', 330); C.setColumnWidth('B', 90); C.setColumnWidth('C', 45)
    C.setAlignment('B2:B%d' % clast, 'right|vcenter')
    doc.recompute()

    # ================================================================ Contrôles
    def g(sh, a):
        try: return sh.get(a)
        except Exception: return ''
    for r in range(2, tg + 1):
        log('Debit', r, [g(D, c + str(r)) for c in 'ABFGIJK'])
    log('Achats', [[g(O, c + str(r)) for c in 'ABC'] for r in range(2, 6)])
    conteneurs = [pg, pd, pl]
    def glob(o):
        for c in conteneurs:
            if o in c.Group:
                return o.Shape.transformed(c.Placement.toMatrix())
        return o.Shape
    def solides(objs):
        out = []
        for o in objs:
            sh = glob(o)
            for sd in sh.Solids:
                out.append((o.Label, sd))
        return out
    fixes = [o for o in pieces if not any(o in c.Group for c in conteneurs)]
    mobiles = [o for o in pieces if o not in fixes]
    log('Pieces soudees : caisse', len(fixes), 'ouvrants', len(mobiles), 'invalides', [o.Label for o in pieces if not o.Shape.isValid()])
    err = [o.Label for o in doc.Objects if 'Invalid' in o.State or 'Error' in o.State]
    log('Objets en erreur', err)
    if err: RAPPORT['erreurs'].append('Objets FreeCAD en erreur : %s' % err[:10])
    def interf(sa, sb, same=False):
        res = []
        for i, (la, a) in enumerate(sa):
            for j, (lb, b) in enumerate(sb):
                if same and j <= i:
                    continue
                if a.BoundBox.intersect(b.BoundBox) and a.common(b).Volume > 0.5:
                    res.append((la, lb))
        return res
    sf = solides(fixes)
    ic = interf(sf, sf, True); log('Interferences caisse', ic)
    if ic: RAPPORT['alertes'].append('Chevauchements dans la caisse : %s' % ic[:5])
    iso = []
    for i, (la, a) in enumerate(sf):
        ba = App.BoundBox(a.BoundBox); ba.enlarge(0.5)
        if not any(ba.intersect(b.BoundBox) and a.distToShape(b)[0] < 1e-3 for j, (lb, b) in enumerate(sf) if j != i):
            iso.append(la)
    log('Pieces isolees', iso)
    if iso: RAPPORT['alertes'].append('Pièces non reliées : %s' % iso[:5])
    extra = [o for o in achats if o.TypeId != 'App::Part' and not any(o in c.Group for c in conteneurs) and o.Ouvrant != 'Chaîne']
    for apg_, apd_, apl_ in (('0', '0', '0'), ('0', '90', '90'), ('0', '135', '135'), ('90', '135', '0'), ('135', '135', '0'), ('20', '0', '0')):
        P.set('AnglePorteG', apg_); P.set('AnglePorteD', apd_); P.set('AnglePlateau', apl_); doc.recompute()
        ap_ = 'G%s/D%s' % (apg_, apd_)
        mob = solides([o for c in conteneurs for o in c.Group
                       if o.Visibility and o.TypeId != 'App::Part' and hasattr(o, 'Shape') and not o.Shape.isNull()])
        io = interf(mob, sf + solides(extra))
        log('Ouvrants', ap_, apl_, 'solides', len(mob), 'interferences', io)
        if io: RAPPORT['alertes'].append('Ouvrants %s / plateau %s : chevauchements %s' % (ap_, apl_, io[:3]))
    P.set('AnglePorteG', '0'); P.set('AnglePorteD', '0'); P.set('AnglePlateau', '0'); doc.recompute()
    vol = sum(sd.Volume for o in pieces for sd in glob(o).Solids)
    log('Masse geometrique inox kg', round(vol / 1e6 * 7.93, 2))
    RAPPORT['masse_kg'] = round(vol / 1e6 * 7.93, 1)
    RAPPORT['pieces_soudees'] = len(pieces)
    RAPPORT['trous_rivets'] = int(D.get('TotalTrous'))
    bb = None
    for l_, sd in solides(pieces):
        bb = sd.BoundBox if bb is None else bb.united(sd.BoundBox)
    log('Encombrement soude', bb)
    tout = solides(pieces) + solides(extra)
    bbt = None
    for l_, sd in tout:
        bbt = sd.BoundBox if bbt is None else bbt.united(sd.BoundBox)
    log('Encombrement avec charnieres (ouvrants fermes)', bbt)


    # ================================================================ Plan
    page = doc.addObject('TechDraw::DrawPage', 'Plan'); page.Label = 'Plan atelier'
    tpl = doc.addObject('TechDraw::DrawSVGTemplate', 'Cartouche')
    tpl.Template = App.getResourceDir() + 'Mod/TechDraw/Templates/ISO/localized/fr/A3_Landscape_FR_m52.svg'
    page.Template = tpl
    ech = next((e for e in (20, 25, 30, 40) if LONG / e <= 86 and LGB / e <= 76 and (HAUT + 90) / e <= 52), 40)
    RAPPORT['echelle_plan'] = '1:%d' % ech
    et = tpl.EditableTexts
    et.update({'Titre': 'Boîte %d x %d x %d' % (LONG, LARG, HAUT),
               'Sous_titre': ('Réf. %s — %s' % (ref, RAPPORT['remorque']))[:60] if RAPPORT['remorque'] else 'Réf. %s' % ref,
               'NomAuteur': 'Généré automatiquement', 'Societe': 'La Boîte à Bivouac', 'Echelle': '1:%d' % ech, 'Version': 'V1.8',
               'DateDeCreation': time.strftime('%d/%m/%Y'), 'Feuille': '1/1', 'A3': 'A3', 'Poids': ''})
    tpl.EditableTexts = et
    doc.recompute()
    page.ViewObject.show(); pump(20)
    src = fixes + conteneurs
    grpv = doc.addObject('TechDraw::DrawProjGroup', 'Vues')
    grpv.X, grpv.Y = 190, 222
    page.addView(grpv)
    grpv.Source = src
    grpv.ProjectionType = 'First angle'
    grpv.ScaleType = 'Custom'; grpv.Scale = 1.0 / ech
    grpv.addProjection('Front'); grpv.addProjection('Top'); grpv.addProjection('Right')
    grpv.spacingX, grpv.spacingY = 18, 12
    grpv.X, grpv.Y = 190, 222
    doc.recompute(); pump(20)
    isov = doc.addObject('TechDraw::DrawViewPart', 'VueIso'); page.addView(isov)
    isov.Source = src
    isov.Direction = App.Vector(1, -1, 0.8); isov.XDirection = App.Vector(1, 1, 0)
    isov.ScaleType = 'Custom'; isov.Scale = 1.0 / (ech * 1.25)
    isov.X, isov.Y = 345, 232
    doc.recompute(); pump(20)

    def sheetview(name, srcs, a, b, x, y, scale):
        sv = doc.addObject('TechDraw::DrawViewSpreadsheet', name); page.addView(sv)
        sv.Source = doc.getObject(srcs); sv.CellStart, sv.CellEnd = a, b
        sv.TextSize = 10.0; sv.Font = 'Sans'
        sv.ScaleType = 'Custom'; sv.Scale = scale
        sv.X, sv.Y = x, y
        return sv
    sheetview('TableDebit', 'Debit', 'A1', 'K%d' % tg, 74, 59, 0.38)
    sheetview('TableCotes', 'Cotes', 'A1', 'C%d' % clast, 72, 146, 0.40)
    sheetview('TableParam', 'Parametres', 'A1', 'C%d' % (last - 3), 263, 132, 0.47)
    sheetview('TableAchats', 'Achats', 'A1', 'D9', 350, 86, 0.42)

    def note(name, lines, x, y, size=3.5, style='Normal'):
        a = doc.addObject('TechDraw::DrawViewAnnotation', name); page.addView(a)
        a.Text = lines; a.TextSize = size; a.Font = 'Sans'; a.TextStyle = style
        a.X, a.Y = x, y
        return a
    fh = (HAUT + 90.0) / ech; th = LGB / float(ech)          # hauteurs des vues de face et de dessus sur le plan
    yf = 222 - fh / 2 - 5; yt = 222 - fh / 2 - 12 - th - 6
    note('LegendeFace', ['Vue de côté — avant (timon) à gauche, arrière (portes) à droite'], 190, yf, 2.8)
    note('LegendeDessus', ['Vue de dessus'], 190, yt, 2.8)
    note('LegendeArriere', ['Vue arrière (côté portes)'], 92, yf, 2.8)
    note('TitreDebit', ['LISTE DE DÉBIT (pièces soudées)'], 48, 111, 3.3, 'Bold')
    note('TitreCotes', ['COTES ET RIVETAGE (mm)'], 50, 180, 3.3, 'Bold')
    note('TitreParam', ['PARAMÈTRES'], 252, 177, 3.3, 'Bold')
    note('TitreAchats', ['ACHATS (hors soudure)'], 330, 105, 3.3, 'Bold')
    note('Notes', ['NOTES',
                   '1. Avant-projet : résistance et fixations non validées.',
                   '2. Inox, soudé. Panneaux bois non représentés : rivetés',
                   '    derrière les ailes des cornières percées (trous Ø DiamRivet).',
                   "3. Cornières d'appui (I à M) là où les panneaux arrivent sur des tubes.",
                   '4. Montant central (D) en T : aile au ras extérieur, arasée',
                   '    aux deux bouts ; les 2 panneaux de côté sont rivetés sur l\'aile.',
                   "5. Portes (N) et plateau (O) : cadres en cornières d'onglet,",
                   '    en retrait de 3 mm. Charnières piano à l\'intérieur : nœud',
                   '    dans le jeu de 6 mm, ailes sur montant et tranche du cadre.',
                   '6. Porte gauche : butées (R) + 2 verrous à ressort (V) dans',
                   '    les gâches (U). Porte droite : en appui sur les taquets (W),',
                   '    verrou à clé : coffre sur X1 (porte droite), gâche sur X2.',
                   '    Ouvrir la droite en premier. Portes : 135° maxi.',
                   '7. Plateau : 2 loquets cadenassables (gâches sur B2), 2 chaînes',
                   '    entre pattes Y1 (caisse) et Y2 (plateau) : arrêt à 90°.',
                   '8. Rehausses (F) sans montant dessous : tenue à valider.',
                   '9. Cotes en mm. Vérifier sur la remorque réelle.'], 352, 153, 2.3)
    doc.recompute(); pump(20)

    Gui.Selection.clearSelection()
    doc.saveAs(FILE)
    log('SAVED', FILE)
    RAPPORT['statut'] = 'a_verifier' if RAPPORT['alertes'] else 'ok'
    if RAPPORT['erreurs']:
        RAPPORT['statut'] = 'erreur'
except Exception:
    log(traceback.format_exc())
    RAPPORT['erreurs'].append('Plantage de la génération : %s' % traceback.format_exc().splitlines()[-1])
fin(0 if RAPPORT['statut'] != 'erreur' else 1)
