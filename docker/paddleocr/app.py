"""
PaddleOCR-VL FastAPI service — pipeline deux conteneurs.

Architecture :
  Ce service (paddleocr-vl) utilise PaddleOCRVL (paddleocr==3.4.1) qui appelle
  llama-server (sidecar) pour l'inférence VLM via HTTP (API OpenAI-compatible).
  llama-server charge PaddleOCR-VL 1.6 GGUF depuis le volume vl_models.
  Ce conteneur gère la détection de layout (PP-DocLayoutV2, local) et l'agrégation.

  Classe utilisée : PaddleOCRVL (PAS PPStructureV3).
    PPStructureV3 = pipeline OCR traditionnel, sans backend VLM externe.
    PaddleOCRVL   = layout local + inférence VLM déportée (llama-cpp-server).
  Les arguments vl_rec_backend / vl_rec_server_url n'existent que sur PaddleOCRVL.

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

Structure interne PaddleOCRVL.predict() (vérifiée sur v3.4.1 source) :
  Retourne list[ResultObject]. Le contenu utile est dans :
    results[0].json["parsing_res_list"]  → list[dict]
  Chaque dict : block_id, block_order, block_label, block_content, block_bbox.
  block_bbox est un np.ndarray — converti en list Python avant sérialisation.
"""

import os
import tempfile
from pathlib import Path

import numpy as np
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import JSONResponse

# URL BASE du sidecar llama-server (sans /v1 — PaddleOCRVL ajoute le chemin).
# La variable d'env LLAMA_SERVER_URL permet de surcharger en dev/test.
LLAMA_SERVER_URL = os.environ.get("LLAMA_SERVER_URL", "http://llama-server:8111")

app = FastAPI(title="PaddleOCR-VL service", version="1.0.0")

_pipeline = None


def get_pipeline():
    """
    Initialise et retourne le pipeline PaddleOCRVL (lazy, une seule fois par process).

    API vérifiée contre la source paddleocr==3.4.1 (GitHub tag v3.4.1) :
      Classe   : PaddleOCRVL (paddleocr/_pipelines/paddleocr_vl.py)
      Args VL  : vl_rec_backend (str), vl_rec_server_url (str, URL base sans /v1)
      device   : "cpu" | "gpu:0" | ... (via **kwargs → PaddleXPipelineWrapper)
      Appel    : pipeline.predict(path) → list[ResultObject]
      Résultat : results[0].json["parsing_res_list"] → list de blocs

    vl_rec_backend valeurs valides :
      "native" | "llama-cpp-server" | "vllm-server" | "sglang-server" |
      "fastdeploy-server" | "mlx-vlm-server"
    """
    global _pipeline
    if _pipeline is None:
        from paddleocr import PaddleOCRVL  # type: ignore[import]
        _pipeline = PaddleOCRVL(
            vl_rec_backend="llama-cpp-server",
            vl_rec_server_url=LLAMA_SERVER_URL,
            device="cpu",
            use_doc_orientation_classify=False,
            use_doc_unwarping=False,
        )
    return _pipeline


@app.get("/health")
def health():
    """Health-check — retourne 200 quand le service est démarré."""
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

    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".jpg", delete=False) as tmp:
            tmp_path = tmp.name
            tmp.write(data)

        pipeline = get_pipeline()

        # predict() retourne list[ResultObject], un élément par image.
        # Pour une image unique, on prend results[0].
        results = pipeline.predict(tmp_path)

    finally:
        if tmp_path is not None:
            Path(tmp_path).unlink(missing_ok=True)

    # --- Extraction des blocs depuis parsing_res_list ---
    # Structure vérifiée : results[0].json["parsing_res_list"] → list[dict]
    # Clés : block_id, block_order, block_label, block_content, block_bbox (np.ndarray)

    blocks = []
    markdown_lines = []

    raw_blocks: list = []
    if results:
        raw_blocks = results[0].json.get("parsing_res_list", [])

    for idx, blk in enumerate(raw_blocks, start=1):
        block_order   = blk.get("block_order",   idx)
        block_label   = str(blk.get("block_label", "text")).lower()
        block_content = blk.get("block_content", "") or ""
        block_bbox    = blk.get("block_bbox", [])

        # block_bbox est un np.ndarray — convertir en list Python avant tout traitement.
        if isinstance(block_bbox, np.ndarray):
            block_bbox = block_bbox.tolist()

        # Normaliser en [x1, y1, x2, y2] si format 2D [[x1,y1],[x2,y1],[x2,y2],[x1,y2]]
        if block_bbox and not isinstance(block_bbox[0], (int, float)):
            try:
                xs = [pt[0] for pt in block_bbox]
                ys = [pt[1] for pt in block_bbox]
                block_bbox = [min(xs), min(ys), max(xs), max(ys)]
            except Exception:
                block_bbox = []

        blocks.append({
            "block_id":      idx,
            "block_order":   block_order,
            "block_label":   block_label,
            "block_content": block_content,
            "block_bbox":    [round(float(v), 1) for v in block_bbox] if block_bbox else [],
        })

        if block_label == "table":
            markdown_lines.append(block_content)
        else:
            markdown_lines.append(str(block_content))

    # Tri final par block_order pour garantir l'ordre de lecture
    blocks.sort(key=lambda b: b["block_order"])

    return JSONResponse({
        "filename":         original_filename,
        "blocks":           blocks,
        "markdown_preview": "\n\n".join(markdown_lines),
    })
