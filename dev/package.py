"""Package only production plugin files, never development data or dependencies."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
target = root / "dist" / "veryshop-product-analysis.zip"
target.parent.mkdir(exist_ok=True)
with ZipFile(target, "w", ZIP_DEFLATED) as archive:
    manifest = [
        "veryshop-product-analysis.php",
        "assets/admin.js", "assets/admin.css", "assets/read-buffer.js",
        "includes/class-model.php", "includes/class-repository.php",
        "includes/class-stock-history.php", "includes/class-reports.php",
        "includes/class-alerts.php", "includes/class-plugin.php", "includes/class-safety.php",
    ]
    for name in sorted(manifest):
        source = root / "plugin" / name
        if not source.is_file():
            raise FileNotFoundError(f"Missing production source: {name}")
        archive.write(source, Path("veryshop-product-analysis") / name)
print(target)
