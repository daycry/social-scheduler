#!/usr/bin/env python3
"""Comprueba que cada ID de audit.md aparece exactamente una vez en `Cubre:` de tasks.md
o en la tabla «Fuera de este plan» de improvement-plan.md. Salida 0 si OK."""
import re, sys, pathlib
d = pathlib.Path(__file__).resolve().parent.parent
audit = (d / "audit.md").read_text(encoding="utf-8")
tasks = (d / "tasks.md").read_text(encoding="utf-8")
plan = (d / "improvement-plan.md").read_text(encoding="utf-8")
ids = set(re.findall(r"^\| ([A-F]-\d\d) \|", audit, re.M))
cub = []
for line in tasks.splitlines():
    m = re.match(r"- \*\*Cubre\*\*: (.*)", line)
    if m: cub += re.findall(r"[A-F]-\d\d", m.group(1))
sec = plan.split("### Fuera de este plan / decisión del usuario", 1)[1].split("\n---", 1)[0]
out = re.findall(r"^\| ([A-F]-\d\d) \|", sec, re.M)
allc = cub + out
dup = sorted({i for i in allc if allc.count(i) > 1})
miss = sorted(ids - set(allc))
extra = sorted(set(allc) - ids)
print(f"audit={len(ids)} cubiertos={len(set(allc))} fuera={len(out)} faltan={miss} sobran={extra} duplicados={dup}")
sys.exit(0 if not (dup or miss or extra) else 1)
