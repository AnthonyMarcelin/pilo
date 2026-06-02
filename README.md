# Pilo

**Pilo** est une PWA de suivi médicamenteux personnel, entièrement auto-hébergée. Elle fonctionne comme un pilulier numérique : ordonnances scannées ou saisies à la main, planning de prise journalier, suivi des paliers dégressifs jour par jour.

**Toutes les données de santé restent sur votre serveur.** Aucune information personnelle ou médicale ne quitte la machine hôte. L'IA (OCR + LLM) tourne en local. Le seul accès réseau optionnel est le téléchargement du référentiel public BDPM (noms et indications officielles des médicaments).

> **Avertissement médical.** Pilo est un aide-mémoire, pas un dispositif médical. Il ne calcule pas de doses, ne détecte pas d'interactions et ne remplace en aucun cas l'ordonnance d'un médecin ni les conseils d'un pharmacien. Le scan IA peut faire des erreurs — **vérifiez toujours les données extraites avant de les enregistrer.**

---

## Fonctionnalités

- **Scan d'ordonnance** — photo (JPEG, HEIC, WebP) ou PDF → extraction automatique du nom, dosage, posologie, paliers dégressifs (ex : corticoïdes 3j×3cp puis 3j×2cp puis 3j×1cp) → formulaire de vérification humaine obligatoire avant enregistrement
- **Saisie manuelle** d'ordonnances
- **Aujourd'hui** — planning de prise par moment (Matin / Midi / Soir / Coucher / Au besoin), palier actif mis en avant avec progression jour X/N, alerte renouvellement stock
- **Mes médicaments** — vue consolidée avec indications officielles BDPM (source HAS/ANSM), notes personnelles
- **Détail ordonnance** — image scannée, liste des items, dates de fin estimées
- **Archivage** des ordonnances terminées

---

## Stack technique

| Composant | Version |
|-----------|---------|
| Laravel | ^13.8 |
| Inertia.js | ^2.0 |
| Vue 3 | ^3.4 |
| Tailwind CSS | ^3.2 |
| Vite | ^8.0 |
| PHP | 8.4-FPM |
| SQLite | WAL mode |
| Redis | 7 |

### Services Docker Compose

| Service | Image | Port interne | Rôle |
|---------|-------|-------------|------|
| `app` | PHP 8.4-FPM (custom) | 9000 | Application Laravel (PHP-FPM) |
| `queue` | PHP 8.4-FPM (custom) | — | Worker scan asynchrone |
| `web` | nginx:stable-alpine | **8080** (exposé) | Serveur HTTP + assets statiques |
| `redis` | redis:7-alpine | 6379 | Cache, sessions, file de jobs |
| `ollama` | ollama/ollama:0.24.0 | 11434 | Serveur LLM — normalisation JSON |
| `llama-server` | ghcr.io/ggml-org/llama.cpp:server | 8111 | Inférence VLM pour PaddleOCR-VL |
| `paddleocr-vl` | custom Python 3.11 | 8000 | Pipeline OCR (layout + blocs texte) |

---

## Architecture du scan OCR

```
Photo / PDF
    │
    ▼
[paddleocr-vl :8000]
    │  PaddleOCR-VL 1.6 (paddleocr==3.4.1, paddlepaddle==3.2.1)
    │  - Détection de layout : PP-DocLayoutV2 (local CPU)
    │  - OCR vision par blocs : PaddleOCR-VL 1.6 GGUF via llama-server
    │  - Redimensionnement ≤ 1500 px grand côté (évite troncature ctx 4096)
    │  - PDF multi-pages : rasterisation par pypdfium2, page par page
    │
    ▼  blocs ordonnés JSON
[ollama :11434]
    │  qwen2.5:7b-instruct — constrained decoding (paramètre `format`)
    │  - Extraction : nom médicament, dosage, posologie, paliers, dates
    │  - Température 0 — déterminisme maximal pour usage médical
    │  - Anti-hallucination : champ absent → null, jamais inventé
    │
    ▼  JSON structuré
[Formulaire de vérification]
    │  L'utilisateur corrige et valide avant tout enregistrement
    │
    ▼
[Base SQLite]
```

Le scan est traité **en arrière-plan** (job Laravel via Redis). Sur CPU, l'inférence prend typiquement **3 à 8 minutes** — c'est normal.

---

## Prérequis

### ⚠️ AVX2 obligatoire

`llama-server` (llama.cpp) requiert les instructions CPU AVX2. **Sans AVX2, le conteneur crashe immédiatement avec `SIGILL`.**

Sur Proxmox, le type de CPU de la VM **doit** être `host` ou `x86-64-v3` — le type `kvm64` par défaut ne passe pas les flags AVX2 au guest. Vérifiez avant d'installer :

```bash
grep -c avx2 /proc/cpuinfo   # doit retourner > 0
```

### Matériel

| Ressource | Minimum | Recommandé |
|-----------|---------|------------|
| Architecture | x86-64 avec AVX2 | — |
| RAM | 6 Go libres | **8 Go** |
| Disque | 20 Go | **30+ Go** |

**Budget RAM au pic d'un scan :**

| Service | RAM estimée |
|---------|------------|
| `llama-server` (PaddleOCR-VL 1.6) | 1.8 – 2.2 Go |
| `ollama` (qwen2.5:7b) | ~2.0 Go |
| `paddleocr-vl` | 0.4 – 0.6 Go |
| `app` + `web` + `queue` + `redis` | 1.0 – 1.5 Go |
| **Total pic** | **~3.7 – 4.8 Go** |

### Logiciels

- Docker ≥ 24
- Docker Compose ≥ 2.20 (`docker compose`, plugin)
- Git

---

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/AnthonyMarcelin/pilo.git
cd pilo
```

### 2. Configurer l'environnement

```bash
cp .env.example .env
```

Éditez `.env` — variables **obligatoires** :

```dotenv
APP_URL=https://pilo.example.com    # URL publique derrière votre reverse proxy

OWNER_EMAIL=vous@example.com        # Email du compte propriétaire
OWNER_PASSWORD=mot-de-passe-fort    # À changer IMPÉRATIVEMENT
```

Les autres valeurs (Redis, Ollama, PaddleOCR, SQLite) sont préconfigurées pour Docker Compose.

### 3. Télécharger les modèles IA

**Modèle PaddleOCR-VL 1.6** (~1.8 Go) — deux fichiers GGUF dans le volume Docker :

```bash
docker run --rm -v pilo_vl_models:/models alpine sh -c "
  apk add --no-cache wget &&
  wget -q --show-progress \
    -O /models/PaddleOCR-VL-1.6-GGUF.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF.gguf' &&
  wget -q --show-progress \
    -O /models/PaddleOCR-VL-1.6-GGUF-mmproj.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF-mmproj.gguf'
"
```

### 4. Construire et démarrer

```bash
docker compose up -d --build
```

Premier build : 10–20 min (téléchargement images + compilation assets Vite dans Docker + dépendances PHP). Les lancements suivants sont rapides.

### 5. Initialiser la base de données

```bash
# Clé d'application Laravel
docker compose exec app php artisan key:generate

# Tables
docker compose exec app php artisan migrate

# Compte propriétaire (lit OWNER_EMAIL / OWNER_PASSWORD depuis .env)
docker compose exec app php artisan db:seed --class=OwnerSeeder
```

### 6. Télécharger le modèle Ollama

```bash
docker compose exec ollama ollama pull qwen2.5:7b-instruct
```

~4.7 Go. À faire une seule fois — persisté dans le volume `ollama_models`.

### 7. Vérifier

Ouvrez `http://<IP_VM>:8080` (ou votre URL publique). Connectez-vous avec `OWNER_EMAIL` / `OWNER_PASSWORD`.

---

## Import du référentiel BDPM

L'import BDPM affiche les **indications officielles** (source HAS/ANSM) sur "Mes médicaments". Optionnel — l'application fonctionne sans.

### Télécharger les fichiers

Sur [base-donnees-publique.medicaments.gouv.fr/telechargement.php](https://base-donnees-publique.medicaments.gouv.fr/telechargement.php) :

| Fichier | Obligatoire | Contenu |
|---------|------------|---------|
| `CIS_CIP_bdpm.txt` | **Oui** | ~15 000 spécialités — noms, codes CIP |
| `CIS_HAS_SMR_bdpm.txt` | Non | Indications (avis SMR HAS) |
| `CIS_GENER_bdpm.txt` | Non | Lien générique → originator |
| `CIS_COMPO_bdpm.txt` | Non | Substances actives (DCI) pour matching par nom |

Sans `CIS_HAS_SMR_bdpm.txt`, les indications seront vides. Recommandé : télécharger les 4 d'un coup.

### Copier les fichiers sur le serveur

> ⚠️ **Piège fréquent.** Le répertoire `storage/app/bdpm/` dans le conteneur appartient à `www-data`. Un `scp` vers l'hôte suivi d'un `mv` peut échouer sur les permissions. Utilisez `docker compose cp` directement.

```bash
# Depuis la machine locale (où les fichiers BDPM ont été téléchargés)
docker compose cp CIS_CIP_bdpm.txt      app:/var/www/html/storage/app/bdpm/
docker compose cp CIS_HAS_SMR_bdpm.txt app:/var/www/html/storage/app/bdpm/
docker compose cp CIS_GENER_bdpm.txt   app:/var/www/html/storage/app/bdpm/
docker compose cp CIS_COMPO_bdpm.txt   app:/var/www/html/storage/app/bdpm/
```

### Lancer l'import

```bash
docker compose exec app php artisan pilo:import-bdpm
```

Idempotent (`updateOrCreate` sur le code CIS). Durée : quelques secondes.

### Rafraîchissement automatique

Un cron tourne le **1er de chaque mois à 03h00** et relance `pilo:import-bdpm` sur les fichiers présents dans `storage/app/bdpm/`. Il ne télécharge **pas** les fichiers automatiquement.

---

## Déploiement / mise à jour

```bash
git pull

# Rebuild les images (nouveaux assets Vite inclus dans l'image)
docker compose up -d --build app queue
```

> ⚠️ **Redémarrez toujours `web` après un rebuild `app`.** Nginx garde en mémoire l'IP interne de l'ancien conteneur `app`. Si `app` est recréé avec une nouvelle IP, nginx continue de router vers l'ancienne → **502 Bad Gateway**. Le restart prend moins de 2 secondes.

```bash
docker compose restart web
```

```bash
# Si de nouvelles migrations existent
docker compose exec app php artisan migrate

# Vider le cache front côté navigateur après mise à jour des assets
# Mac : Cmd+Shift+R   |   Linux/Windows : Ctrl+Shift+R
```

---

## Maintenance

### Backup SQLite avant migration

```bash
docker compose exec app cp \
  /var/sqlite/database.sqlite \
  /var/sqlite/database.sqlite.bak
```

### ⚠️ Nettoyage Docker — saturation disque

Chaque `docker compose up -d --build` conserve les anciennes images et couches. Sur un disque de 50 Go, quelques builds peuvent le saturer.

**Cron hebdomadaire recommandé** sur l'hôte (`crontab -e`) :

```cron
0 4 * * 0  docker image prune -a -f && docker builder prune -a -f
```

Nettoyage manuel :

```bash
docker system df                    # état de la consommation
docker image prune -a -f           # supprimer images inutilisées
docker builder prune -a -f        # vider le cache de build
```

### Logs utiles

```bash
# Application Laravel
docker compose logs app --tail=100

# Worker de scan — JSON Ollama brut, erreurs job
docker compose logs queue --tail=200

# Diagnostics OCR — blocs extraits, stratégie utilisée
docker compose logs paddleocr-vl --tail=200

# JSON brut Ollama sur le dernier scan (dosage, paliers)
docker compose logs queue --since=30m | grep -A 15 '\[OllamaClient\]'
```

---

## Volumes Docker

| Volume | Contenu | Survit à `down` |
|--------|---------|----------------|
| `sqlite_data` | Base de données SQLite | ✅ |
| `storage_images` | Images d'ordonnances uploadées | ✅ |
| `ollama_models` | Modèle qwen2.5:7b (~4.7 Go) | ✅ |
| `vl_models` | GGUF PaddleOCR-VL 1.6 (~1.8 Go) | ✅ |
| `paddle_models` | Cache PaddleOCR Python | ✅ |
| `paddlex_models` | Cache PP-DocLayoutV2 layout model | ✅ |
| `redis_data` | Sessions, queues | ✅ |

> `docker compose down -v` supprime **tous** les volumes — base de données et images d'ordonnances incluses. Ne jamais lancer sans backup.

---

## Variables d'environnement

| Variable | Défaut dans `.env.example` | Description |
|----------|---------------------------|-------------|
| `APP_URL` | `https://pilo.example.com` | URL publique complète |
| `APP_KEY` | *(vide)* | Clé Laravel — générer via `php artisan key:generate` |
| `OWNER_EMAIL` | `owner@example.com` | Email du compte unique |
| `OWNER_PASSWORD` | `changeme123` | **Changer avant le 1er démarrage** |
| `OLLAMA_MODEL` | `qwen2.5:7b-instruct` | Modèle LLM Ollama |
| `TRUSTED_PROXIES` | `*` | IPs proxy de confiance (HTTPS derrière Traefik/nginx) |
| `ALERT_THRESHOLD_DAYS` | `7` | Délai (jours) pour l'alerte renouvellement |
| `DB_DATABASE` | `/var/sqlite/database.sqlite` | Chemin SQLite (dans le volume `sqlite_data`) |

---

## Limites connues

### Posologie dégressive (paliers)

Le modèle LLM (qwen2.5:7b) extrait les paliers dégressifs avec une fiabilité raisonnable mais non garantie. Pour un médicament avec plusieurs paliers de dose (ex : corticoïdes, antidépresseurs), **vérifiez toujours la structure extraite** avant de valider. En cas d'échec, saisir la posologie manuellement.

### Indications BDPM

La source de données pour les indications est le libellé SMR de la Haute Autorité de Santé (fichier `CIS_HAS_SMR_bdpm.txt`). Pour certains médicaments, ce libellé est vague (`"dans les indications de l'AMM"`) ou absent. Dans ce cas, aucune indication n'est affichée — c'est une limite des données publiques disponibles, pas un bug.

Le matching nom OCR → BDPM est approximatif (premier mot du nom, insensible à la casse). Pour des noms ambigus, l'indication affichée peut correspondre à une autre spécialité du même principe actif.

### Performance page "Mes médicaments"

Le chargement de la page exécute une requête SQL par médicament pour récupérer l'indication BDPM. Sur un historique avec de nombreux médicaments distincts, cette page peut être légèrement lente. Cette dette technique est documentée et non bloquante pour un usage mono-utilisateur.

---

## Avertissement médical

Pilo est un **outil d'aide au suivi**, pas un dispositif médical certifié.

- Les indications affichées dans "Mes médicaments" proviennent du référentiel officiel BDPM (ANSM/HAS). Elles décrivent l'usage général du médicament et peuvent différer de votre prescription personnelle.
- Le scan IA peut produire des erreurs : dosage manquant, palier mal structuré, médicament mal reconnu. **Vérifiez toujours les données extraites avant de les enregistrer.**
- Votre médecin et votre pharmacien font foi.
