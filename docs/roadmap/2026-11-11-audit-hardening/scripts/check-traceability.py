#!/usr/bin/env python3
"""Comprueba la trazabilidad de la iniciativa. Salida 0 si todo OK.

1. Cada ID de audit.md aparece exactamente una vez en `Cubre:` de tasks.md
   o en la tabla «Fuera de este plan» de improvement-plan.md.
2. Cada U-xx de audit-unificacion.md aparece exactamente una vez como dueño en la
   tabla «Trazabilidad unificación → tarea» de improvement-plan.md, y figura en
   `Cubre:` o `Cubre (unificación):` de su tarea dueña en tasks.md. No hay U-xx
   desconocidos en tasks.md.
"""
import re, sys, pathlib

d = pathlib.Path(__file__).resolve().parent.parent
audit = (d / "audit.md").read_text(encoding="utf-8")
tasks = (d / "tasks.md").read_text(encoding="utf-8")
plan = (d / "improvement-plan.md").read_text(encoding="utf-8")

# --- hallazgos de la auditoría integral (A-F) ---
ids = set(re.findall(r"^\| ([A-F]-\d\d) \|", audit, re.M))
cub = []
for line in tasks.splitlines():
    m = re.match(r"- \*\*Cubre\*\*: (.*)", line)
    if m:
        cub += re.findall(r"[A-F]-\d\d", m.group(1))
sec = plan.split("### Fuera de este plan / decisión del usuario", 1)[1].split("\n---", 1)[0]
out = re.findall(r"^\| ([A-F]-\d\d) \|", sec, re.M)
allc = cub + out
dup = sorted({i for i in allc if allc.count(i) > 1})
miss = sorted(ids - set(allc))
extra = sorted(set(allc) - ids)
print(f"audit={len(ids)} cubiertos={len(set(allc))} fuera={len(out)} faltan={miss} sobran={extra} duplicados={dup}")
ok = not (dup or miss or extra)

# --- unificación (U-xx) ---
uni = (d / "audit-unificacion.md").read_text(encoding="utf-8")
uids = re.findall(r"^\| (U-\d\d) \|", uni, re.M)
usec = plan.split("## Trazabilidad unificación → tarea", 1)[1].split("\n---", 1)[0]
owners = re.findall(r"^\| (U-\d\d) \| .*? \| (T-\d+) \|", usec, re.M)
oids = [o for o, _ in owners]
udup = sorted({i for i in oids if oids.count(i) > 1})
umiss = sorted(set(uids) - set(oids))
uextra = sorted(set(oids) - set(uids))
sin_cubre = []
for uid, tid in owners:
    m = re.search(rf"^### {tid} — .*?(?=^### T-|^## |\Z)", tasks, re.S | re.M)
    lines = re.findall(r"^- \*\*Cubre(?: \(unificación\))?\*\*: (.*)", m.group(0) if m else "", re.M)
    if not any(uid in line for line in lines):
        sin_cubre.append(f"{uid}@{tid}")
desconocidos = set()
for line in tasks.splitlines():
    m = re.match(r"- \*\*Cubre(?: \(unificación\))?\*\*: (.*)", line)
    if m:
        desconocidos |= {u for u in re.findall(r"U-\d\d", m.group(1)) if u not in uids}
udup_audit = sorted({i for i in uids if uids.count(i) > 1})
print(
    f"unificacion={len(set(uids))} duenos={len(set(oids))} faltan={umiss} sobran={uextra} "
    f"duplicados={sorted(set(udup) | set(udup_audit))} sin_cubre_en_tarea={sin_cubre} "
    f"desconocidos_en_tasks={sorted(desconocidos)}"
)
ok = ok and not (udup or udup_audit or umiss or uextra or sin_cubre or desconocidos)

sys.exit(0 if ok else 1)
