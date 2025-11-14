from pathlib import Path
path = Path("pages/mis_postulaciones.php")
text = path.read_text(encoding="utf-8")
target = "        <span class=\"mp-filter-title\">Ordenar</span>\r\n        <select name=\"orden\">"
print(target)
