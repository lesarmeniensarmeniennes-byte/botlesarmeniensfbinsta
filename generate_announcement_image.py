#!/usr/bin/env python3
"""
Génère l'image d'annonce pour le post de lancement de la nouvelle stratégie.
Usage : OPENAI_API_KEY=sk-... python3 generate_announcement_image.py
"""

import os, time
from pathlib import Path
from openai import OpenAI
from PIL import Image, ImageDraw, ImageFont
import requests
from io import BytesIO

client = OpenAI(api_key=os.environ["OPENAI_API_KEY"])

W, H       = 1080, 1350
BG         = (12, 12, 12)
ORANGE     = (255, 145, 0)
WHITE      = (255, 255, 255)
MARGIN     = 32
_PHOTO_Y   = 160
_PHOTO_H   = 520
_LOGO_SQ   = 150
_BANNER_H  = 200

TITRE = "Notre promesse à la communauté"

IMAGE_PROMPT = (
    "Cinematic aerial view of Mount Ararat at golden hour, dramatic orange and deep purple sky, "
    "ancient Armenian monastery in the foreground surrounded by stone walls, "
    "warm candlelight glowing through small windows, misty valleys below, "
    "photorealistic, epic atmosphere, no people, no text, no logos, movie still quality."
)

_FONT_PATHS = [
    "/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf",
    "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
    "/System/Library/Fonts/Helvetica.ttc",
    "/Library/Fonts/Arial Bold.ttf",
]

def load_font(size):
    for p in _FONT_PATHS:
        if Path(p).exists():
            try:
                return ImageFont.truetype(p, size)
            except Exception:
                pass
    return ImageFont.load_default()

def wrap_text(draw, text, font, max_w):
    words, lines, cur = text.split(), [], []
    for w in words:
        test = " ".join(cur + [w])
        if draw.textbbox((0,0), test, font=font)[2] > max_w and cur:
            lines.append(" ".join(cur)); cur = [w]
        else:
            cur.append(w)
    if cur: lines.append(" ".join(cur))
    return lines

print("⏳ Génération de l'image IA...")
resp = client.images.generate(
    model="gpt-image-1",
    prompt=IMAGE_PROMPT,
    size="1024x1536",
    quality="high",
    n=1,
)
img_bytes = resp.data[0].b64_json
import base64
raw_bytes = base64.b64decode(img_bytes)
raw_photo = Image.open(BytesIO(raw_bytes)).convert("RGB")
raw_path  = f"/tmp/announcement_raw_{int(time.time())}.png"
raw_photo.save(raw_path)
print(f"✅ Image brute : {raw_path}")

# Compositing
canvas = Image.new("RGB", (W, H), BG)
draw   = ImageDraw.Draw(canvas)

# Photo
ph_w, ph_h = W, _PHOTO_H
pw, pph = raw_photo.size
scale = max(ph_w/pw, ph_h/pph)
photo = raw_photo.resize((int(pw*scale), int(pph*scale)), Image.LANCZOS)
ox = (photo.width - ph_w)//2
oy = (photo.height - ph_h)//2
photo = photo.crop((ox, oy, ox+ph_w, oy+ph_h))
canvas.paste(photo, (0, _PHOTO_Y))

# Gradient bas de photo
from PIL import Image as PILImage
grad = PILImage.new("RGBA", (W, 80), (0,0,0,0))
gd   = ImageDraw.Draw(grad)
for i in range(80):
    gd.line([(0,i),(W,i)], fill=(*BG, int(255*(i/79))))
base = canvas.convert("RGBA")
base.paste(grad, (0, _PHOTO_Y + _PHOTO_H - 80), mask=grad.split()[3])
canvas = base.convert("RGB")
draw   = ImageDraw.Draw(canvas)

# Logo haut-gauche
sq_path = Path("assets/logo_square.png")
if sq_path.exists():
    sq = Image.open(sq_path).convert("RGBA").resize((_LOGO_SQ, _LOGO_SQ), Image.LANCZOS)
    mask = Image.new("L", (_LOGO_SQ, _LOGO_SQ), 0)
    ImageDraw.Draw(mask).ellipse([0,0,_LOGO_SQ,_LOGO_SQ], fill=255)
    canvas.paste(sq, (MARGIN, MARGIN), mask=mask)

# Titre
text_y   = _PHOTO_Y + _PHOTO_H + 28
fn_title = load_font(62)
fn_sub   = load_font(34)
lines    = wrap_text(draw, TITRE, fn_title, W - 2*MARGIN)
for line in lines:
    tw = draw.textbbox((0,0), line, font=fn_title)[2]
    draw.text(((W-tw)//2, text_y), line, font=fn_title, fill=WHITE)
    text_y += 74

# Sous-titre orange
sub = "Chaque soir à 19h • 7 thèmes • 1 communauté"
text_y += 10
sw = draw.textbbox((0,0), sub, font=fn_sub)[2]
draw.text(((W-sw)//2, text_y), sub, font=fn_sub, fill=ORANGE)
text_y += 50

# Séparateur orange
draw.rectangle([(MARGIN, text_y), (W-MARGIN, text_y+3)], fill=ORANGE)

# Bannière bas
bn_path = Path("assets/logo_banner.png")
if bn_path.exists():
    bn  = Image.open(bn_path).convert("RGBA")
    bw  = int(bn.width * (_BANNER_H / bn.height))
    bn  = bn.resize((bw, _BANNER_H), Image.LANCZOS)
    ox2 = (bn.width - W)//2
    if ox2 < 0:
        padded = PILImage.new("RGBA", (W, _BANNER_H), (*BG, 255))
        padded.paste(bn, (-ox2, 0), mask=bn.split()[3])
        bn = padded
    else:
        bn = bn.crop((ox2, 0, ox2+W, _BANNER_H))
    # LUT : neutralise teinte visible
    lut = bytes(range(256))*3
    canvas_rgba = canvas.convert("RGBA")
    canvas_rgba.paste(bn, (0, H-_BANNER_H), mask=bn.split()[3])
    canvas = canvas_rgba.convert("RGB")

out = f"images/announcement_{int(time.time())}.png"
Path("images").mkdir(exist_ok=True)
canvas.save(out, quality=95)
print(f"\n🎉 Image finale : {out}")
print("👉 Ouvre le fichier pour la prévisualiser !")
