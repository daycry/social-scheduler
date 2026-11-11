#!/usr/bin/env bash
# Protege la rama main en GitHub (A-02): PR obligatoria y CI verde antes de fusionar.
#
#   bash deploy/github/protect-main.sh --dry-run    # muestra la configuracion actual y la que se aplicaria
#   bash deploy/github/protect-main.sh              # la aplica tras confirmacion explicita
#   bash deploy/github/protect-main.sh --revert     # quita la proteccion (tras confirmacion)
#   bash deploy/github/protect-main.sh --yes        # sin preguntar (solo para uso no interactivo)
#
# Requiere `gh` autenticado con permisos de administrador del repositorio. Es idempotente: PUT sustituye
# la proteccion entera, asi que repetirlo deja el mismo estado.
#
# Variables:
#   REPO             owner/repo (por defecto: el de `gh repo view`)
#   BRANCH           rama a proteger (por defecto: main)
#   CHECK_CONTEXT    nombre del check requerido (por defecto: tests, el job `tests` de .github/workflows/ci.yml;
#                    en la lista de checks de una PR aparece como "CI / tests", pero la API usa solo el nombre del job)
#   APPROVALS        aprobaciones requeridas en la PR (por defecto 0: un solo desarrollador no puede aprobar la suya)
#   ENFORCE_ADMINS   true = tambien obliga a los administradores (por defecto false, para poder desbloquear una urgencia)
set -euo pipefail

DRY_RUN=0; REVERT=0; YES=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --revert) REVERT=1 ;;
        --yes) YES=1 ;;
        -h|--help) sed -n '2,18p' "$0"; exit 0 ;;
        *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
    esac
done

command -v gh >/dev/null 2>&1 || { echo "!! Falta la CLI de GitHub (gh)." >&2; exit 2; }
BRANCH="${BRANCH:-main}"
CHECK_CONTEXT="${CHECK_CONTEXT:-tests}"
APPROVALS="${APPROVALS:-0}"
ENFORCE_ADMINS="${ENFORCE_ADMINS:-false}"
case "$ENFORCE_ADMINS" in true|false) ;; *) echo "!! ENFORCE_ADMINS debe ser true o false." >&2; exit 2 ;; esac
case "$APPROVALS" in ''|*[!0-9]*) echo "!! APPROVALS debe ser un entero >= 0." >&2; exit 2 ;; esac
REPO="${REPO:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
ENDPOINT="repos/$REPO/branches/$BRANCH/protection"

PAYLOAD="$(cat <<JSON
{
  "required_status_checks": { "strict": true, "contexts": ["$CHECK_CONTEXT"] },
  "enforce_admins": $ENFORCE_ADMINS,
  "required_pull_request_reviews": { "required_approving_review_count": $APPROVALS, "dismiss_stale_reviews": true },
  "restrictions": null,
  "required_linear_history": false,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": false
}
JSON
)"

echo "== Proteccion de '$BRANCH' en $REPO =="
echo "Configuracion actual:"
if current="$(gh api "$ENDPOINT" --jq '"  PR obligatoria: \(.required_pull_request_reviews != null)  |  checks requeridos: \(.required_status_checks.contexts // [])  |  admins incluidos: \(.enforce_admins.enabled)"' 2>/dev/null)"; then
    echo "$current"
else
    echo "  (sin proteccion, o sin permiso para leerla)"
fi

if [ "$REVERT" -eq 1 ]; then
    echo "Se va a: QUITAR la proteccion de '$BRANCH' (DELETE $ENDPOINT)."
else
    echo "Se va a aplicar (PUT $ENDPOINT):"
    echo "$PAYLOAD" | sed 's/^/  /'
    echo "Efecto: no se puede empujar directo a '$BRANCH' ni fusionar una PR sin el check '$CHECK_CONTEXT' en verde y al dia con '$BRANCH'; sin force-push ni borrado."
fi

if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] No se ha cambiado nada."
    exit 0
fi

if [ "$YES" -ne 1 ]; then
    [ -t 0 ] || { echo "!! Sin terminal interactiva: usa --yes para confirmar explicitamente." >&2; exit 1; }
    read -r -p "Escribe '$BRANCH' para confirmar: " answer
    [ "$answer" = "$BRANCH" ] || { echo "Cancelado."; exit 1; }
fi

if [ "$REVERT" -eq 1 ]; then
    gh api -X DELETE "$ENDPOINT" >/dev/null 2>&1 || echo "(la rama ya no estaba protegida)"
    echo "== Proteccion retirada =="
else
    echo "$PAYLOAD" | gh api -X PUT "$ENDPOINT" --input - --jq '"== Aplicada: checks \(.required_status_checks.contexts)"'
fi
