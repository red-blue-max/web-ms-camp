"""
Собирает ms-camp-site.zip — то, что загружается в public_html на хостинге.

Запуск из корня проекта:  python tools/make-zip.py

В архив НЕ попадают: _source/, tools/, README.md, .gitignore, сам архив и оригиналы фото
с русскими именами (страница использует только оптимизированные копии).
"""
import zipfile, os, fnmatch
from pathlib import Path

BASE = Path(__file__).resolve().parent.parent
OUT = BASE / "ms-camp-site.zip"
SKIP_DIRS = {"_source", "tools", ".git", ".claude"}
SKIP_FILES = {"README.md", ".gitignore", OUT.name}
ORIGINALS = ("Отель*.jpg", "Бас*.jpg", "Лого.jpg", "Кобзев*.jpg", "Хриплый*.jpg",
             "Хутарева*.jpg", "Полякова*.jpg")

count = 0
with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(BASE):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for f in files:
            if f in SKIP_FILES or any(fnmatch.fnmatch(f, pat) for pat in ORIGINALS):
                continue
            full = Path(root) / f
            z.write(full, full.relative_to(BASE).as_posix())
            count += 1

print(f"{OUT.name}: файлов {count}, размер {OUT.stat().st_size / 1048576:.1f} МБ")
