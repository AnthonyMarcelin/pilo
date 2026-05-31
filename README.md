# Pilo

**Pilo** est une application web auto-hébergée pour visualiser et suivre un traitement médicamenteux au quotidien. Elle présente le traitement sous la forme d'un « pilulier numérique » : la journée découpée en moments (matin, midi, soir, coucher), plus une section « au besoin ». Les ordonnances peuvent être saisies à la main ou photographiées, avec une lecture assistée par une IA qui tourne **entièrement en local**.

> ⚠️ **Avertissement.** Pilo est un **aide-mémoire**, pas un dispositif médical et pas un avis médical. L'application ne calcule pas de doses, ne détecte pas d'interactions et ne remplace en aucun cas l'ordonnance d'un médecin ni les conseils d'un pharmacien. Toute information affichée doit être vérifiée par rapport à l'ordonnance d'origine.

---

## Fonctionnalités

- **Vue du jour** : les prises regroupées par moment (matin / midi / soir / coucher), une section « au besoin » distincte, et les prises particulières en texte libre.
- **Saisie d'ordonnance** : à la main, ou par photo avec extraction automatique (médicaments, posologie, durée) — toujours soumise à validation humaine avant enregistrement.
- **Trois types de prise** : régulière (grille horaire), « au besoin » (avec condition et dose max), et irrégulière (texte libre).
- **Alertes utiles** : renouvellement à venir (estimation de stock), fin de traitement, et signalement de doublon lors d'une saisie.
- **Historique** : toutes les ordonnances sont conservées et consultables, image d'origine incluse.
- **PWA** : installable sur l'écran d'accueil (iPhone, iPad, Android, bureau), consultation du traitement courant hors-ligne.

## Confidentialité

Les données de santé ne quittent jamais le serveur. La lecture des ordonnances est réalisée par des modèles open source exécutés **localement** (aucun appel à une API externe). Pilo est conçu pour être auto-hébergé sur votre propre machine.

## Architecture

- **Backend** : Laravel (PHP), SQLite (WAL), Redis (file d'attente).
- **Frontend** : Inertia.js + Vue 3 + Tailwind CSS, en PWA.
- **IA locale (à la demande)** :
  - [PaddleOCR-VL](https://github.com/PaddlePaddle/PaddleOCR) — image d'ordonnance → texte + structure.
  - [Ollama](https://ollama.com) avec un petit modèle instruct (Qwen2.5) — texte → données structurées.
- Le tout orchestré via Docker Compose.

L'extraction est volontairement découpée en deux étapes (OCR puis structuration) derrière une interface `OcrProvider` enfichable, afin de pouvoir brancher d'autres moteurs.

---

## Prérequis matériel

### Architecture CPU

Pilo vise du **matériel modeste, CPU uniquement** (pas de GPU nécessaire). Les services IA ne démarrent que le temps d'un scan, puis s'arrêtent automatiquement.

### Compatibilité ARM vs x86_64

Toutes les composantes de Pilo tournent sur **ARM64** (Apple Silicon, Raspberry Pi, AWS Graviton…) **et x86_64**. Une seule exception : le scan OCR requiert x86_64, à cause du build PaddleOCR-VL qui ne dispose pas encore d'images ARM64 officielles (limitation du modèle de vision, pas de Pilo).

| Fonctionnalité | ARM64 | x86_64 |
|---|:---:|:---:|
| Application complète (pilulier, alertes, historique) | ✅ | ✅ |
| **Saisie manuelle d'ordonnance** | ✅ | ✅ |
| Import BDPM, référentiel médicaments | ✅ | ✅ |
| PWA hors-ligne | ✅ | ✅ |
| Normalisation Ollama (qwen2.5) | ✅ | ✅ |
| **Scan OCR automatique** | ✗ | ✅ |

> **ARM64** : l'application est pleinement utilisable via la **saisie manuelle**, chemin nominal conçu dès le départ — pas un secours. Seul le scan est limité par le build du modèle de vision. Cette limitation devrait disparaître si PaddlePaddle publie des images ARM64.

### RAM serveur

| Mode | RAM estimée |
|---|---|
| Au repos (app + web + redis + queue) | ~1–1.5 Go |
| **Pic pendant un scan** (Ollama 3B + llama-server VL + paddleocr-vl) | ~4.5–5 Go |

VM recommandée : **6 Go de RAM minimum**. Le pic est transitoire (durée du scan) ; tout est relâché ensuite.

---

## Installation

```bash
git clone <url-du-repo> pilo && cd pilo
cp .env.example .env

# Services de base (mode nominal, IA éteinte)
docker compose up -d --build app web redis queue
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan pilo:import-bdpm   # référentiel médicaments (France)
```

### Préparer les services IA — scan OCR (x86_64 uniquement)

```bash
# 1. Modèles VL dans le volume (~1.8 Go — une seule quantification disponible)
docker run --rm -v pilo_vl_models:/models alpine sh -c "
  apk add --no-cache wget &&
  wget -O /models/PaddleOCR-VL-1.6-GGUF.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF.gguf' &&
  wget -O /models/PaddleOCR-VL-1.6-GGUF-mmproj.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF-mmproj.gguf'
"

# 2. Modèle Ollama de structuration JSON (~1.9 Go)
docker compose --profile ai up -d ollama
docker compose exec ollama ollama pull qwen2.5:3b-instruct

# 3. En exploitation, pilo:ai-up démarre et pilo:ai-down arrête l'IA automatiquement.
```

Exposez ensuite le service `web` derrière votre reverse proxy (TLS recommandé). Voir `.env.example`.

## Référentiel médicaments

Le calcul d'estimation de stock s'appuie sur la **Base de données publique des médicaments** (France), mise à disposition sous licence ouverte : <https://base-donnees-publique.medicaments.gouv.fr>. Ces données sont importées localement et ne confèrent aucun caractère officiel à leur réutilisation.

## Statut

Projet en cours de développement. Les contributions et retours sont les bienvenus.

## Licence

À définir (licence open source). Voir le fichier `LICENSE`.
