#!/usr/bin/env python3
"""Build the WordPress upload ZIP using only the standard library."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(__file__).resolve().parents[1]
plugin = root / "inkos-woocommerce"
target = root / "dist/inkos-woocommerce.zip"
target.parent.mkdir(exist_ok=True)
files = [(p, p.relative_to(root).as_posix()) for p in sorted(plugin.rglob("*")) if p.is_file()]
files.append((root / "README.md", "inkos-woocommerce/README.md"))
with ZipFile(target, "w") as archive:
    for path, name in files:
        info = ZipInfo(name, (1980, 1, 1, 0, 0, 0))
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        archive.writestr(info, path.read_bytes(), compress_type=ZIP_DEFLATED)
print(target)
