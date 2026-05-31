# Fixtures ordonnances — Golden Set Phase 7

Jeu de référence pour l'évaluation du pipeline OCR (Phase 7).
Basé sur 2 ordonnances réelles ANONYMISÉES (noms patient/médecin/RPPS, codes-barres, n° sécu retirés).

## Cas couverts

| Fixture | Type | Cas testé |
|---|---|---|
| fixe-simple.jpg | imprimée | fixe mono-palier, mono-moment |
| fixe-multimoment.jpg | imprimée | fixe « 1 matin / 1 midi / 2 soir » |
| si-besoin.jpg | imprimée | PRN avec condition + max/jour |
| degressif.jpg | imprimée | 3 paliers (corticoïdes) |
| manuscrit.jpg | manuscrite | échec OCR attendu — formulaire vide |
| contradiction-interne.jpg | imprimée | dose simple + paliers sur même item |
| multi-documents/ordo-principale.jpg | imprimée | médicament sur 2 ordonnances liées |
| multi-documents/ordo-securisee.jpg | manuscrite | même médicament, ordonnance sécurisée |

## Résultats attendus

Voir tests/Fixtures/Ordonnances/expected/ (à peupler en Phase 7).
JSON structuré attendu en sortie pipeline pour chaque fixture.

## Note de confidentialité

Aucune donnée personnelle identifiable. Seule la zone traitement est conservée.
