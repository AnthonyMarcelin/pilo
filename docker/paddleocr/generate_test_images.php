<?php
/**
 * Génère les images du golden set pour tester le pipeline OCR Pilo.
 *
 * Images synthétiques mais visuellement réalistes (format A5, fond blanc, texte noir typographique).
 * Reproduisent les cas de test du seeder :
 *   1. ordonnance_imprimee.jpg  — Levothyrox + Gabapentine + Prednisone + Diazépam
 *   2. ordonnance_degressive.jpg — Paroxétine 2 paliers dégressifs
 *   3. ordonnance_manuscrite.jpg — texte manuscrit simulé (dégradé + désalignement)
 *
 * Usage : php generate_test_images.php [output_dir]
 */

$outDir = $argv[1] ?? '/tmp/golden_set';
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeImage(int $w = 1240, int $h = 1754): \GdImage
{
    $img = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);
    return $img;
}

function addText(\GdImage $img, int $x, int $y, string $text, int $size = 16, bool $bold = false): void
{
    $black = imagecolorallocate($img, 20, 20, 20);
    $grey  = imagecolorallocate($img, 100, 100, 100);

    // Utiliser les polices système (monospace pour l'imprimé)
    $fonts = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];
    $font = null;
    foreach ($fonts as $f) {
        if (file_exists($f)) {
            $font = $f;
            break;
        }
    }

    if ($font) {
        imagettftext($img, $size, 0, $x, $y, $bold ? $black : ($size < 14 ? $grey : $black), $font, $text);
    } else {
        // Fallback police interne
        imagestring($img, 5, $x, $y - 16, $text, $black);
    }
}

function hLine(\GdImage $img, int $y, int $x1 = 50, int $x2 = 1190): void
{
    $grey = imagecolorallocate($img, 180, 180, 180);
    imageline($img, $x1, $y, $x2, $y, $grey);
}

function saveJpeg(\GdImage $img, string $path, int $quality = 92): void
{
    imagejpeg($img, $path, $quality);
    imagedestroy($img);
    echo "  → {$path} (" . round(filesize($path) / 1024) . " Ko)\n";
}

// ─── Image 1 : Ordonnance imprimée standard ───────────────────────────────────

echo "Génération ordonnance_imprimee.jpg...\n";
$img = makeImage();

// En-tête médecin
addText($img, 60, 80,  'Dr. Martin Paul', 20, true);
addText($img, 60, 110, 'Médecin Généraliste', 16);
addText($img, 60, 135, '12 rue de la République — 13001 Marseille', 14);
addText($img, 60, 158, 'Tél : 04 91 00 00 00  |  RPPS : 12345678901', 13);
hLine($img, 180);

// Patient et date
addText($img, 60, 210, 'Patient(e) : Mme D.', 15);
addText($img, 60, 235, 'Date : 01/06/2026', 15);
hLine($img, 260);

// Titre
addText($img, 60, 295, 'ORDONNANCE', 22, true);
hLine($img, 320);

// Item 1 — Levothyrox
addText($img, 60,  360, 'Levothyrox 100 µg, comprimé sécable', 17, true);
addText($img, 80,  390, '1 comprimé le matin à jeun, 30 min avant le petit-déjeuner', 15);
addText($img, 80,  415, 'Durée : 365 jours — QSP 3 mois', 14);
hLine($img, 440);

// Item 2 — Gabapentine
addText($img, 60,  470, 'Gabapentine 100 mg, gélule', 17, true);
addText($img, 80,  500, '1 gélule matin, 1 gélule midi, 2 gélules soir', 15);
addText($img, 80,  525, 'Durée : 90 jours', 14);
hLine($img, 550);

// Item 3 — Prednisone (dégressive simple)
addText($img, 60,  580, 'Prednisone 20 mg, comprimé', 17, true);
addText($img, 80,  610, '2 comprimés le matin pendant 5 jours', 15);
addText($img, 80,  635, 'puis 1 comprimé le matin pendant 5 jours', 15);
addText($img, 80,  660, 'puis arrêt', 15);
hLine($img, 690);

// Item 4 — Diazépam (si besoin)
addText($img, 60,  720, 'Diazépam 10 mg, comprimé', 17, true);
addText($img, 80,  750, 'Si besoin, en cas d\'anxiété aiguë', 15);
addText($img, 80,  775, '1 comprimé par prise, maximum 1 comprimé par jour', 15);
hLine($img, 800);

// Bas de page
addText($img, 60, 840, 'Signature :', 14);
addText($img, 60, 870, 'Dr. Martin Paul', 16, true);

saveJpeg($img, "{$outDir}/ordonnance_imprimee.jpg");

// ─── Image 2 : Ordonnance dégressive Paroxétine ───────────────────────────────

echo "Génération ordonnance_degressive.jpg...\n";
$img = makeImage();

addText($img, 60, 80,  'Dr. Lefebvre Sophie', 20, true);
addText($img, 60, 110, 'Psychiatre', 16);
addText($img, 60, 135, '8 avenue Gambetta — 75020 Paris', 14);
hLine($img, 165);

addText($img, 60, 200, 'Patient(e) : Mme D.', 15);
addText($img, 60, 225, 'Date : 29/05/2026', 15);
hLine($img, 255);
addText($img, 60, 290, 'ORDONNANCE SÉCURISÉE', 22, true);
hLine($img, 315);

addText($img, 60,  360, 'Paroxétine 20 mg, comprimé pelliculé', 18, true);
addText($img, 80,  395, '2 comprimés le matin pendant 28 jours (schéma dégressif ci-dessous)', 15);
addText($img, 60,  430, '   Schéma posologique :', 15, true);
addText($img, 80,  460, '   • Phase 1 : 2 comprimés/matin × 7 jours', 15);
addText($img, 80,  485, '   • Phase 2 : 1 comprimé/matin × 15 jours', 15);
addText($img, 80,  510, '   • Phase 3 : arrêt', 15);
addText($img, 60,  545, '   Durée totale : 22 jours', 15);
hLine($img, 570);

addText($img, 60, 605, 'À administrer sous surveillance médicale.', 14);
addText($img, 60, 630, 'Ne pas interrompre brutalement.', 14);
hLine($img, 660);

addText($img, 60, 700, 'Dr. Lefebvre Sophie', 16, true);

saveJpeg($img, "{$outDir}/ordonnance_degressive.jpg");

// ─── Image 3 : Ordonnance manuscrite simulée ──────────────────────────────────

echo "Génération ordonnance_manuscrite.jpg...\n";
$img = makeImage(1240, 900);

// Fond légèrement texturé (papier)
$noise_color = imagecolorallocate($img, 248, 246, 240);
for ($i = 0; $i < 5000; $i++) {
    imagesetpixel($img, rand(0, 1239), rand(0, 899),
        imagecolorallocate($img, rand(240, 255), rand(240, 255), rand(235, 255)));
}

// Texte "manuscrit" simulé : police normale mais inclinaison légère et irrégulière
$dark = imagecolorallocate($img, 30, 30, 80);

// On simule une écriture en utilisant imagestring (police bitmap → moins lisible)
$lines = [
    [60,  60,  "Dr R.  Auteur-Dubon"],
    [60,  90,  "Généraliste"],
    [60,  130, "Mme D  -  le  3/6/26"],
    [60,  180, "Rp/"],
    [60,  220, "Metformine  850  mg"],
    [60,  255, " 1 cp  matin  + soir  x  3  mois"],
    [60,  295, "Amlodipine  5mg"],
    [60,  330, " 1 cp  le  matin"],
    [60,  370, "Atorvastatine  20mg"],
    [60,  405, " 1 cp  le  soir  HS"],
    [60,  460, "Signature"],
];

foreach ($lines as [$x, $y, $text]) {
    $jitter = rand(-3, 3);
    imagestring($img, 5, $x + rand(-2, 2), $y + $jitter, $text, $dark);
}

saveJpeg($img, "{$outDir}/ordonnance_manuscrite.jpg", 85);

echo "\nGolden set généré dans {$outDir}/\n";
echo "3 images :\n";
foreach (glob("{$outDir}/*.jpg") as $f) {
    echo "  " . basename($f) . "  (" . round(filesize($f)/1024) . " Ko)\n";
}
