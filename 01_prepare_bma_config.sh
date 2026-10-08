#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="${1:-$PWD}"
PRIVATE_DIR="${2:-$HOME/bma-private}"
TARGET_CONFIG="$PRIVATE_DIR/config.php"

echo "BMA Analytics - Prepare Private Configuration"
echo "Project root : $PROJECT_ROOT"
echo "Private dir  : $PRIVATE_DIR"
echo

if [[ ! -d "$PROJECT_ROOT" ]]; then
  echo "ERROR: Project root does not exist: $PROJECT_ROOT" >&2
  exit 1
fi

SOURCE_CONFIG=""
for candidate in   "$PROJECT_ROOT/config.example.php"   "$PROJECT_ROOT/config/config.example.php"
do
  if [[ -f "$candidate" ]]; then
    SOURCE_CONFIG="$candidate"
    break
  fi
done

if [[ -z "$SOURCE_CONFIG" ]]; then
  echo "ERROR: Could not find config.example.php." >&2
  exit 1
fi

mkdir -p "$PRIVATE_DIR"
chmod 700 "$PRIVATE_DIR"

if [[ -e "$TARGET_CONFIG" ]]; then
  echo "NOTICE: $TARGET_CONFIG already exists and will not be overwritten."
  exit 0
fi

cp "$SOURCE_CONFIG" "$TARGET_CONFIG"
chmod 600 "$TARGET_CONFIG"

echo "Created: $TARGET_CONFIG"
echo "Edit it now, for example:"
echo "  nano \"$TARGET_CONFIG\""
echo
echo "Then run:"
echo "  ./02_install_bma_database.sh \"$PROJECT_ROOT\" \"$TARGET_CONFIG\""
