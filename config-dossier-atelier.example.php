<?php
// Modèle de configuration du déclenchement du dossier atelier.
// À copier sur l'hébergement OVH dans le dossier PARENT de laboiteabivouac/ (non publié sur le web),
// sous le nom config-dossier-atelier.php. Ne jamais le versionner ni le coller dans une conversation.
return [
    'GITHUB_TOKEN' => 'github_pat_A_REMPLACER', // jeton « fine-grained » : dépôt la-boite-a-bivouac seul, permission Contents : Read and write
    'GITHUB_REPO'  => 'matthieumandagot-design/la-boite-a-bivouac',
    'ADMIN_KEY'    => 'A_REMPLACER_par_40_caracteres_aleatoires',
];
