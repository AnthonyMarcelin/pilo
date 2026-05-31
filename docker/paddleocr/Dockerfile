# PaddleOCR — backend CPU standard (PP-OCRv4, non-VL)
#
# Choix : standard PaddleOCR (non-VL) plutôt que PaddleOCR-VL.
# Raisons :
#   - Think-Core/paddleocr-vl-cpu : requiert 16 GB+ RAM — disqualifié pour notre cible ~2-3 GB.
#   - PaddleOCR-VL 1.6 + llama.cpp CPU : trop récent (mai 2026), RAM ~4-5 GB au pic, pas encore
#     de Docker mature.
#   - PP-OCRv4 (non-VL) : RAM ~900 MB-1.1 GB au pic, 1-3 s/image sur CPU 4-vCPU,
#     multiples déploiements Docker+FastAPI en production, `lang='fr'` disponible.
#
# Upgrade path vers VL : quand PaddleOCR-VL-1.6-GGUF CPU Docker est stable (voir SPEC.md §8),
# remplacer par : llama-server sidecar (port 8111) + vl_rec_backend="llama-cpp-server".
# Le contrat API /ocr reste identique.
#
# CPU only — pas de section deploy/gpu.

FROM python:3.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libsm6 libxrender1 libxext6 libgomp1 \
 && rm -rf /var/lib/apt/lists/*

# numpy<2.0 requis par paddlepaddle 2.6.x (incompatibilité numpy 2.x)
RUN pip install --no-cache-dir \
    numpy==1.26.4 \
    paddlepaddle==2.6.2 \
    paddleocr==2.9.1 \
    fastapi==0.115.6 \
    "uvicorn[standard]==0.32.1" \
    python-multipart==0.0.20 \
    Pillow==11.1.0

WORKDIR /app

# Pré-téléchargement des modèles PP-OCRv4 fr au moment du build
# (évite un cold-start de 20-30 s au premier POST /ocr en production)
RUN python -c "from paddleocr import PaddleOCR; PaddleOCR(use_angle_cls=True, lang='fr', use_gpu=False, show_log=False)"

COPY app.py .

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
