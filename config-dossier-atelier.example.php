<?php
// Modèle de configuration du déclenchement du dossier atelier.
// À copier sur l'hébergement OVH dans le dossier PARENT de laboiteabivouac/ (non publié sur le web),
// sous le nom config-dossier-atelier.php. Ne jamais le versionner ni le coller dans une conversation.
return [
    'GITHUB_TOKEN' => 'github_pat_A_REMPLACER', // jeton « fine-grained » : dépôt la-boite-a-bivouac seul, permission Contents : Read and write
    'GITHUB_REPO'  => 'matthieumandagot-design/la-boite-a-bivouac',
    'ADMIN_KEY'    => 'A_REMPLACER_par_40_caracteres_aleatoires',
    // Dossier offert (modèle libre) : clé de chiffrement de l'adresse du demandeur, 32 octets en base64.
    // La MÊME valeur va dans le secret GitHub ATELIER_CLE_EMAIL. Sans elle, le configurateur refuse les demandes.
    'CLE_EMAIL'    => 'A_REMPLACER_par_44_caracteres_base64',
];
