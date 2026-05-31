"""
PaddleOCR-VL FastAPI service — pipeline deux conteneurs.

Architecture :
  Ce service (paddleocr-vl) appelle llama-server (sidecar) pour l'inférence VLM.
  llama-server charge PaddleOCR-VL 1.6 Q8_0 (ou 1.5 Q8_0 en fallback) depuis le volume vl_models.
  Ce conteneur gère la détection de layout (PP-DocLayoutV2, local) et l'agrégation des blocs.

Contrat API POST /ocr :
  Retourne des blocs ordonnés préservant la spatialité de l'ordonnance.
  block_order = ordre de lecture (essentiel pour associer nom → dosage → posologie).
  block_label = "text" | "table" | "figure" — utilisé par l'étape Ollama.
  block_bbox = [x1, y1, x2, y2] — affiché dans le formulaire de validation humaine.

  Exemple de réponse :
  {
    "filename": "ordonnance.jpg",
    "blocks": [
      {
        "block_id": 1,
        "block_order": 1,
        "block_label": "text",
        "block_content": "Dr. Martin Paul, Médecin généraliste",
        "block_bbox": [x1, y1, x2, y2]
      },
      {
        "block_id": 2,
        "block_order": 2,
        "block_label": "table",
        "block_content": "<table>...</table>",
        "block_bbox": [x1, y1, x2, y2]
      }
    ],
    "markdown_preview": "..."
  }

Note PaddleOCR-VL : le VLM est entraîné sur 5 tâches fixes (OCR, Table Recognition,
Formula Recognition, Chart Recognition, Seal Recognition). Il ne répond PAS à des
prompts d'extraction personnalisés. L'extraction sémantique (nom médicament, dosage…)
est faite en aval par Ollama, pas ici.
"""

import io
import os
import tempfile
from pathlib import Path

from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import JSONResponse

# URL du sidecar llama-server (OpenAI-compatible, port 8111 par défaut).
# La variable d'env LLAMA_SERVER_URL permet de surcharger en dev/test.
LLAMA_SERVER_URL = os.environ.get("LLAMA_SERVER_URL", "http://llama-server:8111/v1")

app = FastAPI(title="PaddleOCR-VL service", version="1.0.0")

_pipeline = None


def get_pipeline():
    """
    Initialise et retourne le pipeline PaddleOCR-VL (lazy, une seule fois par process).

    TODO — vérifier lors du premier run :
      L'API exacte de PPStructureV3 avec vl_rec_backend="llama-cpp-server" dépend
      de la version paddleocr >= 3.4.1. Si l'import ou les paramètres changent entre
      versions mineures, ajuster ici.

      Import attendu (PaddleOCR >= 3.4.1) :
        from paddleocr import PPStructureV3

      Instanciation attendue :
        PPStructureV3(
            device="cpu",
            vl_rec_backend="llama-cpp-server",
            vl_rec_server_url=LLAMA_SERVER_URL,
            show_log=False,
        )

      Si PPStructureV3 n'est pas disponible dans la version installée, essayer :
        from paddleocr import PaddleOCR
        PaddleOCR(use_doc_orientation_classify=True, use_doc_unwarping=True,
                  vl_rec_backend="llama-cpp-server", ...)
      et ouvrir un ticket avec la version exacte de paddleocr.
    """
    global _pipeline
    if _pipeline is None:
        # TODO: confirmer l'API exacte sur la version installée (paddleocr >= 3.4.1).
        # Le import et les paramètres ci-dessous correspondent à la documentation
        # PaddleOCR 3.4.x pour le backend llama-cpp-server.
        try:
            from paddleocr import PPStructureV3  # type: ignore[import]
            _pipeline = PPStructureV3(
                device="cpu",
                vl_rec_backend="llama-cpp-server",
                vl_rec_server_url=LLAMA_SERVER_URL,
                show_log=False,
            )
        except ImportError as exc:
            raise RuntimeError(
                f"PPStructureV3 introuvable dans paddleocr installé. "
                f"Vérifier que paddleocr >= 3.4.1 est installé et que l'API n'a pas changé. "
                f"Erreur : {exc}"
            ) from exc
    return _pipeline


@app.get("/health")
def health():
    """Health-check utilisé par le worker de scan (pilo:ai-up attend ce 200)."""
    return {"status": "ok", "llama_server_url": LLAMA_SERVER_URL}


@app.post("/ocr")
async def run_ocr(file: UploadFile = File(...)):
    """
    POST /ocr — reçoit une image d'ordonnance, retourne des blocs ordonnés.

    Le résultat préserve l'ordre de lecture spatial (block_order) et le type de bloc
    (block_label : text / table / figure / formula / seal).
    Les blocs "table" ont un block_content HTML (<table>…</table>).
    Les blocs "text" ont un block_content en texte plat.

    Ce résultat est transmis tel quel à Ollama pour normalisation JSON.
    NE PAS aplatir en texte brut avant d'envoyer à Ollama — l'ordre spatial est
    l'information critique pour associer nom médicament → dosage → posologie.
    """
    if not file.content_type or not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Image attendue (image/*)")

    data = await file.read()
    original_filename = file.filename or "ordonnance.jpg"

    # tmp_path initialisé à None : le guard "if tmp_path is not None" dans le finally
    # couvre le cas où NamedTemporaryFile lui-même échouerait.
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
            tmp_path = tmp.name  # assigné avant write() — garantit le cleanup si write échoue
            tmp.write(data)

        pipeline = get_pipeline()

        # TODO: vérifier le type de retour exact de PPStructureV3.__call__ sur la
        # version installée. Les attributs ci-dessous (block_order, block_label,
        # block_bbox, block_content) correspondent à la structure documentée dans
        # PaddleOCR >= 3.4. Si les noms d'attributs diffèrent, adapter les accès.
        result = pipeline(tmp_path)
    finally:
        if tmp_path is not None:
            Path(tmp_path).unlink(missing_ok=True)

    # --- Normalisation du résultat en contrat API ---
    # TODO: adapter si la structure de retour de PPStructureV3 diffère.
    # Attendu : result est un dict avec une clé "blocks" (liste) ou similaire.
    # En cas de doute, logger `type(result)` et `result` au premier run.

    blocks = []
    markdown_lines = []

    raw_blocks = []
    if isinstance(result, dict):
        # Format attendu : {"blocks": [...], ...}
        raw_blocks = result.get("blocks") or result.get("layout_result") or []
    elif isinstance(result, list):
        # Certaines versions retournent directement une liste de blocs
        raw_blocks = result

    for idx, blk in enumerate(raw_blocks, start=1):
        # Extraction défensive — les noms exacts dépendent de la version paddleocr.
        # Priorité : attributs objet > clés dict.
        def _get(obj, *keys):
            for k in keys:
                try:
                    v = getattr(obj, k, None)
                    if v is not None:
                        return v
                except Exception:
                    pass
                if isinstance(obj, dict) and k in obj:
                    return obj[k]
            return None

        block_order = _get(blk, "block_order", "order", "index") or idx
        block_label = str(_get(blk, "block_label", "block_type", "type") or "text").lower()
        block_content = _get(blk, "block_content", "content", "text") or ""
        block_bbox = _get(blk, "block_bbox", "bbox", "box") or []

        # Normaliser block_bbox en [x1, y1, x2, y2]
        if block_bbox and not isinstance(block_bbox[0], (int, float)):
            # Format [[x1,y1],[x2,y1],[x2,y2],[x1,y2]] → [x1, y1, x2, y2]
            try:
                xs = [pt[0] for pt in block_bbox]
                ys = [pt[1] for pt in block_bbox]
                block_bbox = [min(xs), min(ys), max(xs), max(ys)]
            except Exception:
                block_bbox = []

        blocks.append({
            "block_id": idx,
            "block_order": block_order,
            "block_label": block_label,
            "block_content": block_content,
            "block_bbox": [round(float(v), 1) for v in block_bbox] if block_bbox else [],
        })

        # Markdown preview (usage debug / formulaire de validation)
        if block_label == "table":
            markdown_lines.append(block_content)
        else:
            markdown_lines.append(str(block_content))

    # Tri final par block_order pour garantir l'ordre de lecture
    blocks.sort(key=lambda b: b["block_order"])

    return JSONResponse({
        "filename": original_filename,
        "blocks": blocks,
        "markdown_preview": "\n\n".join(markdown_lines),
    })
