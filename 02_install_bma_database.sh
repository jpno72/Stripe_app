#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_ROOT="${1:-$PWD}"
CONFIG_FILE="${2:-$HOME/bma-private/config.php}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

echo "BMA Analytics - Database Install / Migration"
echo "Project root : $PROJECT_ROOT"
echo "Config file  : $CONFIG_FILE"
echo

[[ -d "$PROJECT_ROOT" ]] || fail "Project root does not exist: $PROJECT_ROOT"
[[ -f "$CONFIG_FILE" ]] || fail "Private config file does not exist: $CONFIG_FILE"
[[ -f "$PROJECT_ROOT/bin/install.php" ]] || fail "Missing bin/install.php"
[[ -d "$PROJECT_ROOT/sql/migrations" ]] || fail "Missing sql/migrations directory"

command -v php >/dev/null 2>&1 || fail "PHP CLI is not available."

php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'   || fail "PHP 8.2 or newer is required."

for ext in pdo_mysql mbstring; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);"     || fail "Required PHP extension is missing: $ext"
done

echo "PHP version : $(php -r 'echo PHP_VERSION;')"
echo "pdo_mysql   : OK"
echo "mbstring    : OK"
echo

cd "$PROJECT_ROOT"

if [[ -f composer.json ]]; then
  if command -v composer >/dev/null 2>&1; then
    echo "Installing production Composer dependencies..."
    composer install --no-dev --prefer-dist --optimize-autoloader
    echo
  elif [[ -f vendor/autoload.php ]]; then
    echo "Composer not found, but vendor/autoload.php exists; continuing."
    echo
  else
    fail "composer is unavailable and vendor/autoload.php does not exist."
  fi
fi

echo "Running installer preflight/check..."
php bin/install.php --check --config="$CONFIG_FILE"
echo

if [[ "${BMA_INSTALL_YES:-0}" != "1" ]]; then
  echo "The next command will APPLY all pending numbered migrations."
  read -r -p "Apply migrations now? [y/N] " answer
  case "$answer" in
    y|Y|yes|YES) ;;
    *) echo "Cancelled before applying migrations."; exit 0 ;;
  esac
fi

echo
echo "Applying migrations..."
php bin/install.php --apply --config="$CONFIG_FILE"

echo
echo "Running post-install check..."
php bin/install.php --check --config="$CONFIG_FILE"

echo
echo "============================================================"
echo "Database installation/migration completed."
echo "Confirm the output reports 0 pending migrations."
echo "============================================================"
