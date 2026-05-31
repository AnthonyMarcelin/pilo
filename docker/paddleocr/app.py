import io
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.responses import JSONResponse
from PIL import Image
import numpy as np

app = FastAPI(title="PaddleOCR service", version="0.1.0")

_ocr = None


def get_ocr():
    global _ocr
    if _ocr is None:
        from paddleocr import PaddleOCR
        _ocr = PaddleOCR(use_angle_cls=True, lang="fr", use_gpu=False, show_log=False)
    return _ocr


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
async def run_ocr(file: UploadFile = File(...)):
    if not file.content_type or not file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Image attendue (image/*)")

    data = await file.read()
    img = np.array(Image.open(io.BytesIO(data)).convert("RGB"))

    result = get_ocr().ocr(img, cls=True)

    blocks = []
    texts = []
    for page in (result or []):
        for item in (page or []):
            box, (text, conf) = item
            blocks.append({
                "text": text,
                "confidence": round(float(conf), 3),
                "box": [[round(float(c), 1) for c in pt] for pt in box],
            })
            texts.append(text)

    return JSONResponse({"text": "\n".join(texts), "blocks": blocks})
