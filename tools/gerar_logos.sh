#!/bin/bash
# Regenera logos.js a partir do Simple Icons (CC0). Uso: bash tools/gerar_logos.sh
set -euo pipefail
VER=16.34.0
TMP=$(mktemp -d)
for s in grafana prometheus vault ubuntu centos apple google linux; do
  curl -sSf -o "$TMP/$s.svg" "https://cdn.jsdelivr.net/npm/simple-icons@$VER/icons/$s.svg"
done
python3 - "$TMP" <<'PY'
import re, sys, json, pathlib
src = sys.argv[1]
colors = {"grafana": "#F46800", "prometheus": "#E6522C", "vault": "#FFEC6E", "ubuntu": "#E95420",
          "centos": "#9A97E8", "apple": "#E6EDF6", "google": "#4285F4", "linux": "#FCC624"}
out = {s: {"d": re.search(r'<path d="([^"]+)"', pathlib.Path(f"{src}/{s}.svg").read_text()).group(1), "color": c}
       for s, c in colors.items()}
pathlib.Path("logos.js").write_text(
    "/* Logotipos dos ativos na topologia.\n * Fonte: Simple Icons 16.34.0 (https://simpleicons.org), licença CC0 1.0. Caixa 24×24.\n"
    " * Gerado pelo script em tools/; cores claras onde a cor oficial some no fundo escuro. */\n"
    "window.DC360_LOGOS = " + json.dumps(out, indent=1) + ";\n")
PY
rm -rf "$TMP"
echo "logos.js atualizado"
