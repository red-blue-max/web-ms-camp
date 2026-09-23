"""
Генерация оптимизированных изображений для лендинга.

Запуск из корня проекта:  python tools/optimize-images.py
Нужен Python 3 + Pillow (pip install pillow).

Исходники (оригиналы с русскими именами) лежат в assets/<папка>/ и не изменяются.
Рядом создаются веб-версии с латинскими именами: WebP + JPG-фолбэк в нескольких ширинах.
Если заменили фото — положите новый файл с тем же именем и перезапустите скрипт.
"""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = Path(__file__).resolve().parent.parent
A = ROOT / "assets"
NAVY = (0, 46, 98)
RED = (210, 18, 69)


def save(im, base, width, q_webp=78, q_jpg=80):
    im = im.convert("RGB")
    if im.width > width:
        im = im.resize((width, round(im.height * width / im.width)), Image.LANCZOS)
    im.save(f"{base}-{width}.webp", "WEBP", quality=q_webp, method=6)
    im.save(f"{base}-{width}.jpg", "JPEG", quality=q_jpg, optimize=True, progressive=True)


def crop_ratio(im, rw, rh, cy=0.5, cx=0.5):
    """Обрезка до пропорции rw:rh с центром кадра в (cx, cy) долях."""
    w, h = im.size
    if w / h > rw / rh:
        nw = round(h * rw / rh)
        x = min(max(0, round(w * cx - nw / 2)), w - nw)
        return im.crop((x, 0, x + nw, h))
    nh = round(w * rh / rw)
    y = min(max(0, round(h * cy - nh / 2)), h - nh)
    return im.crop((0, y, w, y + nh))


def open_img(p):
    return ImageOps.exif_transpose(Image.open(p))


# ---------- Отель: 1000x667 → 600 / 1000
for i in range(1, 19):
    im = open_img(A / "hotel" / f"Отель{i}.jpg")
    for w in (600, 1000):
        save(im, A / "hotel" / f"hotel-{i:02d}", w)

# ---------- Бассейн: 1728x2304 (портрет) → 4:3 превью + полноразмер для лайтбокса
for i in range(1, 5):
    im = open_img(A / "pool" / f"Бас{i}.jpg")
    for w in (600, 1200):
        save(crop_ratio(im, 3, 4), A / "pool" / f"pool-{i:02d}", w)

# ---------- Hero: Бас2 (чаша бассейна). Мобильная версия — портрет, десктоп — 16:9
hero = open_img(A / "pool" / "Бас2.jpg")
save(crop_ratio(hero, 9, 14, cy=0.55), A / "pool" / "hero-m", 800, q_webp=70, q_jpg=72)
land = crop_ratio(hero, 16, 9, cy=0.52)
for w in (1280, 1728):
    save(land, A / "pool" / "hero", w, q_webp=70, q_jpg=72)

# ---------- Тренеры: 3:4
coaches = {
    "kobzev": "Кобзев Денис Викторович.jpg",
    "khutareva": "Хутарева Екатерина Евгеньевна.jpg",
    "khriplyy": "Хриплый Валерий Вадимович.jpg",
}
for slug, name in coaches.items():
    im = crop_ratio(open_img(A / "coaches" / name), 3, 4, cy=0.5)
    for w in (480, 800):
        save(im, A / "coaches" / f"coach-{slug}", w)

# ---------- Логотип: белый фон → прозрачность (color-to-alpha), + белая версия
logo = open_img(A / "logo" / "Лого.jpg").convert("RGB")
bbox = ImageOps.invert(logo).getbbox()
logo = logo.crop((bbox[0] - 8, bbox[1] - 8, bbox[2] + 8, bbox[3] + 8))
px = logo.load()
color = Image.new("RGBA", logo.size)
white = Image.new("RGBA", logo.size)
cp, wp = color.load(), white.load()
for y in range(logo.height):
    for x in range(logo.width):
        r, g, b = px[x, y]
        a = max(255 - r, 255 - g, 255 - b)
        if a < 6:
            continue
        f = a / 255
        un = lambda c: max(0, min(255, round((c - 255 * (1 - f)) / f)))
        cp[x, y] = (un(r), un(g), un(b), a)
        wp[x, y] = (255, 255, 255, a)
for im, name in ((color, "logo"), (white, "logo-white")):
    for h in (56, 112):
        w = round(im.width * h / im.height)
        r = im.resize((w, h), Image.LANCZOS)
        r.save(A / "logo" / f"{name}-{h}.png", optimize=True)
        r.save(A / "logo" / f"{name}-{h}.webp", "WEBP", quality=90, method=6)

# ---------- Иконки сайта
def font(size, bold=True):
    for f in ("arialbi.ttf" if bold else "arial.ttf", "DejaVuSans-Bold.ttf"):
        try:
            return ImageFont.truetype(f, size)
        except OSError:
            pass
    return ImageFont.load_default()


def icon(size):
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle((0, 0, size - 1, size - 1), radius=size // 5, fill=NAVY)
    f = font(round(size * 0.62))
    d.text((size * 0.47, size * 0.54), "M", font=f, fill="white", anchor="mm")
    s = size * 0.13
    cx, cy = size * 0.8, size * 0.22
    d.ellipse((cx - s, cy - s, cx + s, cy + s), fill=RED)
    return im


icon(180).save(A / "icons" / "apple-touch-icon.png")
icon(512).save(A / "icons" / "icon-512.png")
icon(32).save(A / "icons" / "favicon-32.png")
icon(64).save(ROOT / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])

# ---------- OG-картинка 1200x630
og = crop_ratio(open_img(A / "pool" / "Бас2.jpg"), 1200, 630, cy=0.55).resize((1200, 630), Image.LANCZOS).convert("RGBA")
grad = Image.new("RGBA", og.size)
gd = ImageDraw.Draw(grad)
for x in range(1200):
    a = int(235 * max(0, 1 - x / 1000))
    gd.line([(x, 0), (x, 630)], fill=NAVY + (a,))
og = Image.alpha_composite(og, grad)
lw = white.resize((round(white.width * 90 / white.height), 90), Image.LANCZOS)
og.alpha_composite(lw, (64, 60))
d = ImageDraw.Draw(og)
d.text((64, 240), "Зимние сборы", font=font(84), fill="white")
d.text((64, 340), "по плаванию", font=font(84), fill="white")
d.rounded_rectangle((64, 470, 64 + 560, 470 + 76), radius=10, fill=RED)
d.text((88, 508), "2–10 января 2027", font=font(46), fill="white", anchor="lm")
og.convert("RGB").save(A / "og-image.jpg", quality=85, optimize=True)

print("Готово")
