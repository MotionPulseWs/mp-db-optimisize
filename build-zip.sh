#!/usr/bin/env bash
#
# Genera el ZIP distribuible del plugin, listo para subir a WordPress.
#
#   bash build-zip.sh
#
# Produce  mp-db-optimisize-<version>.zip  en la raíz del proyecto, con la
# carpeta mp-db-optimisize/ dentro (WordPress usa ese nombre como slug del
# plugin: cambiarlo crearía una instalación duplicada en los sitios que ya
# lo tienen). La versión se lee de la cabecera de optimize-db.php.

set -euo pipefail

SLUG="mp-db-optimisize"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"

# Archivos y carpetas de desarrollo que no deben viajar en el ZIP
EXCLUDES=(".git" ".gitignore" ".vscode" "build" "dist" "CLAUDE.md" "build-zip.sh")

VERSION="$(grep -m1 '^Version:' "$ROOT/optimize-db.php" | sed 's/^Version:[[:space:]]*//' | tr -d '\r')"
if [ -z "$VERSION" ]; then
    echo "ERROR: no se pudo leer la versión de optimize-db.php" >&2
    exit 1
fi

ZIP_NAME="$SLUG-$VERSION.zip"
echo "Empaquetando $SLUG v$VERSION ..."

# Limpiar restos de una ejecución anterior
rm -rf "$BUILD"
rm -f "$ROOT/$ZIP_NAME"
mkdir -p "$STAGE"

# Copiar todo salvo lo excluido y los propios .zip
shopt -s dotglob nullglob
for item in "$ROOT"/*; do
    name="$(basename "$item")"

    skip=0
    for ex in "${EXCLUDES[@]}"; do
        if [ "$name" = "$ex" ]; then
            skip=1
            break
        fi
    done
    case "$name" in
        *.zip) skip=1 ;;
    esac

    if [ "$skip" -eq 0 ]; then
        cp -R "$item" "$STAGE/"
    fi
done
shopt -u dotglob nullglob

# Comprimir: usa zip si existe, si no recurre a PowerShell (Windows).
#
# NOTA: no se usa Compress-Archive. En Windows PowerShell 5.1 escribe las rutas
# internas con '\', y el ZIP exige '/': un servidor Linux descomprimiría un
# único directorio plano con ficheros llamados "mp-db-optimisize\index.php" en
# lugar de la carpeta del plugin. Por eso se construye el ZIP a mano
# normalizando cada ruta.
if command -v zip >/dev/null 2>&1; then
    ( cd "$BUILD" && zip -rq "$ROOT/$ZIP_NAME" "$SLUG" )
elif command -v powershell.exe >/dev/null 2>&1; then
    to_win() {
        if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s' "$1"; fi
    }

    PACK_PS1="$(mktemp -t mpodb-pack-XXXXXX.ps1)"
    cat > "$PACK_PS1" <<'PSEOF'
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$base = $env:MPODB_BUILD
$stage = $env:MPODB_STAGE
$dest = $env:MPODB_ZIP
if (Test-Path $dest) { Remove-Item $dest -Force }
$zip = [System.IO.Compression.ZipFile]::Open($dest, 'Create')
try {
    Get-ChildItem -Path $stage -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($base.Length).TrimStart('\', '/').Replace('\', '/')
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $rel, 'Optimal')
    }
} finally {
    $zip.Dispose()
}
PSEOF

    MPODB_BUILD="$(to_win "$BUILD")" \
    MPODB_STAGE="$(to_win "$STAGE")" \
    MPODB_ZIP="$(to_win "$ROOT/$ZIP_NAME")" \
        powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass \
            -File "$(to_win "$PACK_PS1")"

    rm -f "$PACK_PS1"
else
    echo "ERROR: no hay ni 'zip' ni 'powershell.exe' disponibles para comprimir." >&2
    exit 1
fi

rm -rf "$BUILD"

echo "Listo: $ZIP_NAME"
