"""
Генератор SVG-тайла «разделительная дорожка бассейна» (assets/lane.svg).
Поплавки-диски в боковом ракурсе: белые → синие → красные, как на реальной дорожке.
Тайл бесшовный: повторяется по горизонтали (background-repeat: repeat-x).

Запуск:  python tools/make-lane.py
"""
from pathlib import Path

P = 27          # шаг поплавка
H = 48          # высота тайла
CY = H / 2      # ось троса
SEQ = ["w", "w", "w", "b", "b", "b", "r", "r", "r"]
W = P * len(SEQ)

COL = {  # верх, середина, низ; цвет торца
    "w": ("#FFFFFF", "#E7EEF6", "#9FB0C4", "#F4F8FC"),
    "b": ("#5D8EE6", "#1D55C0", "#0A2A6E", "#2F66CF"),
    "r": ("#FF5C7C", "#D21245", "#7A0626", "#E3264F"),
}

out = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">', "<defs>"]
for k, (a, b, c, _) in COL.items():
    out.append(
        f'<linearGradient id="{k}" x1="0" y1="0" x2="0" y2="1">'
        f'<stop offset="0" stop-color="{a}"/><stop offset=".45" stop-color="{b}"/><stop offset="1" stop-color="{c}"/>'
        "</linearGradient>"
    )
out.append("</defs>")

# трос
out.append(f'<rect x="0" y="{CY-1.2}" width="{W}" height="2.4" fill="#0B2A55" fill-opacity=".55"/>')

for i, k in enumerate(SEQ):
    x = i * P + 3          # левый край обода
    bw, top, bot = 14, 5, H - 5
    face = COL[k][3]
    g = []
    # конус-втулка слева (соединение с предыдущим поплавком)
    g.append(f'<path d="M{x-4} {CY-1.6} L{x+1} {CY-6} L{x+1} {CY+6} L{x-4} {CY+1.6} Z" fill="url(#{k})"/>')
    # обод (толщина диска)
    g.append(f'<rect x="{x}" y="{top}" width="{bw}" height="{bot-top}" rx="4" ry="9" fill="url(#{k})"/>')
    # рёбра на ободе
    for gx in (x + 4.4, x + 9.6):
        g.append(f'<rect x="{gx-0.6}" y="{top+3}" width="1.2" height="{bot-top-6}" rx=".6" fill="#001A3A" fill-opacity=".22"/>')
    # блик
    g.append(f'<rect x="{x+1.4}" y="{top+4}" width="1.6" height="11" rx=".8" fill="#fff" fill-opacity=".55"/>')
    # торец диска со спицами
    fx = x + bw
    g.append(f'<ellipse cx="{fx}" cy="{CY}" rx="4.2" ry="{(bot-top)/2}" fill="{face}" stroke="#001A3A" stroke-opacity=".28" stroke-width=".8"/>')
    g.append(f'<ellipse cx="{fx}" cy="{CY}" rx="2.6" ry="{(bot-top)/2-4}" fill="none" stroke="#001A3A" stroke-opacity=".25" stroke-width=".8"/>')
    g.append(f'<path d="M{fx} {top+5} V{bot-5} M{fx-2.2} {CY-8} L{fx+2.2} {CY+8} M{fx-2.2} {CY+8} L{fx+2.2} {CY-8}" stroke="#001A3A" stroke-opacity=".22" stroke-width=".8"/>')
    # конус-втулка справа
    g.append(f'<path d="M{fx+1} {CY-5} L{fx+6} {CY-1.4} L{fx+6} {CY+1.4} L{fx+1} {CY+5} Z" fill="url(#{k})" fill-opacity=".9"/>')
    out.append("<g>" + "".join(g) + "</g>")

out.append("</svg>")
dst = Path(__file__).resolve().parent.parent / "assets" / "lane.svg"
dst.write_text("\n".join(out), encoding="utf-8")
print(dst, W, "x", H)
