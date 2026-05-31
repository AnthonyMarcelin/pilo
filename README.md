# Pilo

**Pilo** est une application web auto-hébergée pour visualiser et suivre un traitement médicamenteux au quotidien. Elle présente le traitement sous la forme d'un « pilulier numérique » : la journée découpée en moments (matin, midi, soir, coucher), plus une section « au besoin ». Les ordonnances peuvent être saisies à la main ou photographiées, avec une lecture assistée par une IA qui tourne **entièrement en local**.

> **Avertissement médical.** Pilo est un **aide-mémoire**, pas un dispositif médical. L'application ne calcule pas de doses, ne détecte pas d'interactions et ne remplace en aucun cas l'ordonnance d'un médecin ni les conseils d'un pharmacien. Toute information affichée doit être vérifiée par rapport à l'ordonnance d'origine.

---

## Fonctionnalités

- **Vue du jour** : les prises regroupées par moment (matin / midi / soir / coucher), une section « au besoin » distincte, et les prises particulières en texte libre.
- **Saisie d'ordonnance** : à la main, ou par photo avec extraction automatique (médicaments, posologie, durée) — toujours soumise à validation humaine avant enregistrement.
- **Trois types de prise** : régulière (grille horaire), « au besoin » (avec condition et dose max), et irrégulière (texte libre).
- **Posologie dégressive** : gestion de paliers successifs (ex. sevrage corticoïdes) avec affichage « jour X/Y » et alerte de changement de dose.
- **Alertes utiles** : renouvellement à venir (estimation de stock), fin de traitement, signalement de doublon lors d'une saisie.
- **Historique** : toutes les ordonnances sont conservées et consultables, image d'origine incluse.
- **PWA** : installable sur l'écran d'accueil (iPhone, iPad, Android, bureau), consultation du traitement courant hors-ligne.

## Confidentialité

Les données de santé ne quittent jamais le serveur. La lecture des ordonnances est réalisée par des modèles open source exécutés **localement** (aucun appel à une API externe). Pilo est conçu pour être auto-hébergé sur votre propre machine.

## Architecture

- **Backend** : Laravel (PHP 8.3+), SQLite (WAL), Redis (file d'attente).
- **Frontend** : Inertia.js + Vue 3 + Tailwind CSS, PWA (vite-plugin-pwa).
- **IA locale (à la demande)** :
  - [PaddleOCR-VL](https://github.com/PaddlePaddle/PaddleOCR) — image d'ordonnance → texte + structure spatiale.
  - [Ollama](https://ollama.com) avec Qwen2.5 3B — texte structuré → JSON normalisé.
- Le tout orchestré via Docker Compose.

---

## Prérequis matériel

### Architecture CPU

Pilo vise du **matériel modeste, CPU uniquement** (pas de GPU nécessaire). Les services IA ne démarrent que le temps d'un scan, puis s'arrêtent automatiquement.

### Compatibilité ARM vs x86_64

| Fonctionnalité | ARM64 | x86_64 |
|---|:---:|:---:|
| Application complète (pilulier, alertes, historique) | ✅ | ✅ |
| **Saisie manuelle d'ordonnance** | ✅ | ✅ |
| Import BDPM, référentiel médicaments | ✅ | ✅ |
| PWA hors-ligne | ✅ | ✅ |
| Normalisation Ollama (qwen2.5:3b) | ✅ | ✅ |
| **Scan OCR automatique** | ✗ | ✅ |

> **ARM64 (Apple Silicon, Graviton…)** : l'application est pleinement utilisable via la **saisie manuelle**, chemin nominal conçu dès le départ. Seul le scan automatique est limité par l'absence d'image ARM64 pour PaddleOCR-VL.

### RAM serveur

| Mode | RAM estimée |
|---|---|
| Au repos (app + web + redis + queue) | ~1–1,5 Go |
| Pic pendant un scan (Ollama 3B + llama-server VL + paddleocr-vl) | ~4,5–5 Go |

VM recommandée : **6 Go de RAM minimum**. Le pic est transitoire (durée du scan) ; la RAM est relâchée ensuite.

---

## Installation

```bash
git clone <url-du-repo> pilo && cd pilo
cp .env.example .env
# Éditez .env : APP_URL, APP_KEY (généré ci-dessous), et OWNER_EMAIL/OWNER_PASSWORD

# Services de base (mode nominal, IA éteinte)
docker compose up -d --build app web redis queue
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan pilo:import-bdpm   # référentiel médicaments (France)
```

### Préparer les services IA — scan OCR (x86_64 uniquement)

```bash
# 1. Modèles VL dans le volume (~1,8 Go — une seule quantification disponible)
docker run --rm -v pilo_vl_models:/models alpine sh -c "
  apk add --no-cache wget &&
  wget -O /models/PaddleOCR-VL-1.6-GGUF.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF.gguf' &&
  wget -O /models/PaddleOCR-VL-1.6-GGUF-mmproj.gguf \
    'https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6-GGUF/resolve/main/PaddleOCR-VL-1.6-GGUF-mmproj.gguf'
"

# 2. Modèle Ollama de structuration JSON (~1,9 Go)
docker compose --profile ai up -d ollama
docker compose exec ollama ollama pull qwen2.5:3b-instruct

# Les services IA s'allument/s'éteignent automatiquement à chaque scan (via pilo:ai-up/down).
```

---

## Déploiement derrière un reverse proxy

Pilo est conçu pour tourner derrière un reverse proxy avec TLS. Le conteneur `web` (Nginx) écoute sur le port `8080` par défaut et sert l'application.

### Exemple : Nginx en reverse proxy

```nginx
server {
    listen 443 ssl http2;
    server_name pilo.example.com;

    ssl_certificate     /etc/ssl/certs/pilo.crt;
    ssl_certificate_key /etc/ssl/private/pilo.key;

    # Limite pour les uploads d'ordonnances (images)
    client_max_body_size 20m;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
    }
}

server {
    listen 80;
    server_name pilo.example.com;
    return 301 https://$host$request_uri;
}
```

### Exemple : Caddy (automatique HTTPS)

```caddyfile
pilo.example.com {
    reverse_proxy localhost:8080 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}
```

### Variables d'environnement importantes

Dans `.env`, ajustez :

```env
APP_URL=https://pilo.example.com
APP_ENV=production
APP_DEBUG=false
```

Les headers `X-Forwarded-*` sont lus automatiquement par Laravel via le middleware `TrustProxies`.

---

## Référentiel médicaments (BDPM)

Le calcul d'estimation de stock s'appuie sur la **Base de données publique des médicaments** (ANSM / Ministère chargé de la santé), disponible sur <https://base-donnees-publique.medicaments.gouv.fr> sous licence ouverte (Etalab / Open Data). Ces données sont importées localement et ne confèrent aucun caractère officiel à leur réutilisation par Pilo.

La commande `php artisan pilo:import-bdpm` charge les fichiers `CIS_CIP_bdpm.txt`, `CIS_HAS_SMR_bdpm.txt` et `CIS_GENER_bdpm.txt` depuis le répertoire courant. Un cron mensuel (1er du mois, 3h) maintient le référentiel à jour.

---

## Licence

MIT — voir [LICENSE](LICENSE).
