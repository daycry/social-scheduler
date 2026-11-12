# Protección de `main` y aviso de CI en rojo (A-02)

**Por qué:** `main` no tenía protección y la CI estuvo **6 días en rojo sin que nadie lo viera**: se podía fusionar con los tests fallando y nadie recibía aviso. Dos piezas lo cierran:

| Pieza | Qué hace |
|---|---|
| `protect-main.sh` | Protege `main` en GitHub: PR obligatoria, check requerido `tests`, rama al día, sin force-push ni borrado |
| `.github/workflows/ci-failure-notify.yml` | Si el workflow `CI` termina en `failure`, `cancelled`, `timed_out` o `startup_failure` en un push a `main`, abre (o comenta) la issue «CI en rojo en main»; GitHub avisa por correo a quien vigila el repo. La cierra sola cuando vuelve a verde |

## Estado requerido: `CI / tests`

En la lista de checks de una PR se ve como **`CI / tests`** (workflow `CI`, job `tests` de `.github/workflows/ci.yml`). Desde T-15 la CI se reparte en jobs paralelos (`php`, `node`, `studio-runtime`, `openapi`, `audit`) y **`tests` es un job agregador** (`needs` de todos ellos, `if: always()`) que solo pasa si pasan todos: así el check requerido sigue siendo uno solo y estable aunque se añadan o renombren jobs. Si añades un job a `ci.yml`, añádelo también a su `needs`; si cambias el nombre de `tests`, cambia `CHECK_CONTEXT` y repite `protect-main.sh`. Los jobs sueltos no son checks requeridos, pero cualquiera que falle hace fallar `tests`. El workflow nocturno `nightly.yml` (E2E troceado) no es un check requerido. La API de protección de ramas usa solo el **nombre del job**: `tests`. Es lo que configura el script (`CHECK_CONTEXT`, por defecto `tests`); comprobado con `gh api repos/<owner>/<repo>/commits/main/check-runs --jq '.check_runs[].name'`.

## Aplicarlo (lo hace el usuario, con permisos de administrador del repo)

```bash
gh auth status                                   # sesión con permiso de admin
bash deploy/github/protect-main.sh --dry-run     # muestra la configuración actual y la que aplicaría; no cambia nada
bash deploy/github/protect-main.sh               # pide escribir «main» para confirmar
gh api repos/<owner>/<repo>/branches/main/protection   # comprobar
```

Es idempotente (repetirlo deja el mismo estado). Opciones por variable: `APPROVALS` (por defecto 0; con un solo desarrollador no puedes aprobar tu propia PR), `ENFORCE_ADMINS` (por defecto **`true`**: obliga también a los administradores; con un único desarrollador, que es administrador, es lo que cierra A-02: sin esto podría empujar a `main` o saltarse la CI), `CHECK_CONTEXT`, `BRANCH`, `REPO`. Sin terminal interactiva exige `--yes`.

Efecto práctico: `main` solo recibe cambios por PR y con `tests` en verde sobre la última versión de `main`; el despliegue (`deploy/hestia/deploy.sh`) además comprueba que el commit tenga la CI verde.

## Hotfix urgente con la CI rota

Con `ENFORCE_ADMINS=true` ni el administrador salta la protección. Para un hotfix puntual: `ENFORCE_ADMINS=false bash deploy/github/protect-main.sh` (relaja solo a los administradores; sigue exigiendo PR) o `--revert` (quita todo); en cuanto termines, vuelve a aplicar `bash deploy/github/protect-main.sh`.

## Revertir

```bash
bash deploy/github/protect-main.sh --revert      # quita la protección (pide confirmación)
```

o, en GitHub, Settings → Branches → regla de `main` → Delete.

## Avisos de fallos de Actions

- La issue automática solo se crea con fallos del workflow `CI` en push a `main` (no en ramas ni PR, donde el fallo ya se ve en la propia PR).
- Complemento sin código: en GitHub → Settings → Notifications → Actions, activa «Send notifications for failed workflows only» (correo/web) para tus propias ejecuciones. Y vigila el repo («Watch» → Custom → Issues) para recibir la issue.
- El workflow `workflow_run` solo se ejecuta cuando está en la rama por defecto: empieza a funcionar tras fusionarlo en `main`.
