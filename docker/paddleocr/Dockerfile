# PaddleOCR-VL — pipeline Python (wrapper FastAPI seulement)
#
# Architecture deux conteneurs :
#   - Ce conteneur : PaddleOCRVL (paddleocr==3.4.1), PP-DocLayoutV2 layout local,
#     appelle llama-server via HTTP pour l'inférence VLM (PaddleOCRVL.predict()).
#   - Sidecar llama-server requis — voir docker-compose.yml.
#
# Modèle VL : PaddleOCR-VL 1.6 GGUF (primaire) ou 1.5 (fallback documenté).
#   Les poids GGUF sont chargés par llama-server depuis le volume vl_models.
#   Ce conteneur ne charge aucun modèle VLM lui-même.
#
# Pourquoi paddleocr==3.4.1 (épinglé) :
#   - 3.4.0 : backend llama-cpp-server silencieusement cassé.
#   - 3.4.1 : première version stable avec PaddleOCRVL + llama-cpp-server.
#   - Ne jamais utiliser "paddleocr>=3.4.1" sans borne haute — l'API a changé
#     entre versions mineures (PPStructureV3 ≠ PaddleOCRVL, args incompatibles).
#
# Pourquoi paddlepaddle==3.2.1 :
#   - paddleocr 3.4.1 requiert paddlepaddle >= 3.2.1 (via PaddleX 3.4.x).
#   - paddlepaddle==2.6.2 (2.x) est incompatible avec paddleocr 3.x.
#
# CPU only — pas de section deploy/gpu.

FROM python:3.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libsm6 libxrender1 libxext6 libgomp1 libgl1 \
 && rm -rf /var/lib/apt/lists/*

# paddleocr==3.4.1 installe paddlex[ocr-core] comme dépendance core.
# paddlex[ocr]==3.4.3 : version minimum requise pour que le backend "llama-cpp-server"
#   soit valide dans GenAIConfig (paddleX/inference/models/common/genai.py).
#   - paddleX 3.4.1 : llama-cpp-server ABSENT de GenAIConfig → ValidationError.
#   - paddleX 3.4.2+ : llama-cpp-server ajouté → fonctionne.
#   Épinglé à 3.4.3 (dernier patch 3.4.x). paddleocr 3.4.1 accepte paddleX>=3.4.0,<3.5.0.
#   Contenu [ocr] : opencv-contrib-python==4.10.0.84, scipy, shapely, scikit-learn,
#   tokenizers>=0.19, safetensors>=0.7.0, lxml, sentencepiece, tiktoken, etc.
# paddlepaddle==3.2.1 : requis par paddleocr 3.x (via PaddleX 3.4.x). CPU-only.
# httpx : client HTTP async (non requis par paddleocr, gardé pour compatibilité).
RUN pip install --no-cache-dir \
    "paddlepaddle==3.2.1" \
    "paddleocr==3.4.1" \
    "paddlex[ocr]==3.4.3" \
    "fastapi==0.115.6" \
    "uvicorn[standard]==0.32.1" \
    "python-multipart==0.0.20" \
    "Pillow==11.1.0" \
    "httpx==0.27.0"

WORKDIR /app

# Pré-téléchargement du modèle PP-DocLayoutV2 au moment du build.
#
# On utilise PPStructureV3 (pas PaddleOCRVL) : PPStructureV3 ne nécessite pas
# llama-server pour s'initialiser → pas d'erreur de connexion au build.
# Les deux classes partagent le même cache paddlex (~/.paddlex/official_models/).
# Le modèle VLM (GGUF) est dans llama-server — ne jamais tenter de le charger ici.
#
# Cache path : /root/.paddlex/official_models/PP-DocLayoutV2/
# Volume docker-compose : paddlex_models:/root/.paddlex (persist entre redémarrages).
# Si le téléchargement échoue (pas d'internet au build), || true évite l'échec du
# build ; le modèle sera téléchargé au premier démarrage du conteneur.
RUN python -c "\
from paddleocr import PPStructureV3; \
PPStructureV3(device='cpu')" 2>/dev/null || true

COPY app.py .

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
