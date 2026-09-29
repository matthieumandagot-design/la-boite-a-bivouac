# -*- coding: utf-8 -*-
# La Boîte à Bivouac — générateur automatique du dossier atelier (étape 2 : exports et aperçus)
# Lancement : BIVOUAC_OUT=dossier/ BIVOUAC_MACRO=Exporter_pour_atelier.FCMacro xvfb-run freecad exporter_dossier.py
import os, json, time, glob, traceback
OUT = os.path.abspath(os.environ.get('BIVOUAC_OUT', 'dossier'))
MACRO = os.path.abspath(os.environ.get('BIVOUAC_MACRO', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'Exporter_pour_atelier.FCMacro')))
os.environ['BIVOUAC_NO_DIALOG'] = '1'
chemin_rapport = os.path.join(OUT, 'rapport_generation.json')
rapport = json.load(open(chemin_rapport, encoding='utf-8'))
try:
    import FreeCAD as App, FreeCADGui as Gui
    from PySide import QtWidgets
    from pivy import coin
    def pump(n=10):
        for _ in range(n):
            QtWidgets.QApplication.processEvents(); time.sleep(0.05)
    fc = os.path.join(OUT, rapport['fichier'] + '.FCStd')
    doc = App.openDocument(fc)
    Gui.getMainWindow().showMaximized(); pump(20)
    # 1. exports atelier (STEP soudé, STEP ensemble, plan PDF, débit CSV)
    exec(open(MACRO, encoding='utf-8').read(), {'__name__': '__main__'})
    # 2. aperçus
    Gui.Selection.clearSelection()
    v = Gui.ActiveDocument.mdiViewsOfType('Gui::View3DInventor')[0]
    Gui.getMainWindow().setActiveWindow(v); pump(10)
    v.setCameraType('Orthographic')
    P = doc.getObject('Parametres')
    def vue(d, nom):
        d = App.Vector(*d); d.normalize(); up = App.Vector(0, 0, 1)
        xc = up.cross(d); xc.normalize(); yc = d.cross(xc)
        m = App.Matrix(xc.x, yc.x, d.x, 0, xc.y, yc.y, d.y, 0, xc.z, yc.z, d.z, 0, 0, 0, 0, 1)
        v.setCameraOrientation(App.Placement(m).Rotation); pump(5); v.fitAll(); pump(10)
        v.saveImage(os.path.join(OUT, rapport['fichier'] + '_' + nom + '.png'), 1600, 1100, 'White')
    def angles(g, d_, pl):
        P.set('AnglePorteG', g); P.set('AnglePorteD', d_); P.set('AnglePlateau', pl); doc.recompute(); pump(10)
    angles('0', '0', '0');     vue((1, -1, 0.8), 'apercu_arriere')
    angles('0', '0', '90');    vue((-1, -0.9, 0.6), 'apercu_avant_plateau_ouvert')
    angles('0', '0', '0')
    for f in glob.glob(os.path.join(OUT, '*.FCBak')):
        os.remove(f)
    rapport['fichiers'] = sorted(os.path.basename(f) for f in glob.glob(os.path.join(OUT, rapport['fichier'] + '*')))
except Exception:
    rapport.setdefault('erreurs', []).append("Échec de l'export : %s" % traceback.format_exc().splitlines()[-1])
    rapport['statut'] = 'erreur'
    open(os.path.join(OUT, 'journal_export.txt'), 'w', encoding='utf-8').write(traceback.format_exc())
json.dump(rapport, open(chemin_rapport, 'w', encoding='utf-8'), ensure_ascii=False, indent=2)
os._exit(0)
