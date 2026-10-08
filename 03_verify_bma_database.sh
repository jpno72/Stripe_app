#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="${1:-$PWD}"
CONFIG_FILE="${2:-$HOME/bma-private/config.php}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

echo "BMA Analytics - Database Verification"
echo "Project root : $PROJECT_ROOT"
echo "Config file  : $CONFIG_FILE"
echo

[[ -d "$PROJECT_ROOT" ]] || fail "Project root does not exist: $PROJECT_ROOT"
[[ -f "$CONFIG_FILE" ]] || fail "Private config file does not exist: $CONFIG_FILE"
[[ -f "$PROJECT_ROOT/bin/install.php" ]] || fail "Missing bin/install.php"
[[ -f "$PROJECT_ROOT/sql/schema.sql" ]] || fail "Missing sql/schema.sql"
[[ -d "$PROJECT_ROOT/sql/migrations" ]] || fail "Missing sql/migrations directory"
command -v php >/dev/null 2>&1 || fail "PHP CLI is not available."

echo "1) PHP syntax..."
php -l "$PROJECT_ROOT/bin/install.php" >/dev/null
echo "   PASS"
echo

echo "2) Required PHP extensions..."
for ext in pdo_mysql mbstring; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);"     || fail "Required extension missing: $ext"
  echo "   $ext: PASS"
done
echo

echo "3) Migration files..."
find "$PROJECT_ROOT/sql/migrations" -maxdepth 1 -type f -name '*.sql' -printf '   %f\n' | sort
echo

echo "4) Installer/database status..."
cd "$PROJECT_ROOT"
php bin/install.php --check --config="$CONFIG_FILE"

echo
echo "============================================================"
echo "Verification finished."
echo "A current install should report no pending migrations and"
echo "no migration checksum/order/table-inventory problems."
echo "============================================================"
