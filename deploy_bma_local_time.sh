#!/usr/bin/env bash
# BMA Analytics: staged local-time deployment with private backups and rollback.
# Designed for cPanel Bash 4.2; NEVER runs git pull/reset or database migrations.
set -Eeuo pipefail
umask 077

PROJECT='/home/bbmmacom/repositories/Stripe_app'
PUBLIC='/home/bbmmacom/public_html/customers.bloommma.com/public'
PRIVATE='/home/bbmmacom/bma-private'
STAGE='/home/bbmmacom/bma-private/time-ready.VZVNWk'
PHP='/opt/cpanel/ea-php82/root/usr/bin/php'
URL='https://customers.bloommma.com/'

# Exact files changed by the timezone correction, excluding docs/tests/examples.
FILES=(
  'src/AppTime.php'
  'src/database.php'
  'app/Demographics/DemographicRepository.php'
  'app/Members/MemberProfileRepository.php'
  'app/Membership/MembershipRepository.php'
  'app/Revenue/RevenueFilters.php'
  'app/Revenue/RevenueRepository.php'
  'app/Views/components.php'
  'app/Views/layout.php'
  'public/admin/import-errors.php'
  'public/admin/imports.php'
  'public/admin/matches.php'
  'public/admin/users.php'
  'public/api/member-transactions.php'
  'public/api/membership-members.php'
  'public/api/revenue-transactions.php'
  'public/assets/js/member-profile.js'
  'public/assets/js/revenue.js'
  'public/demographics.php'
  'public/index.php'
  'public/member.php'
  'public/membership.php'
  'public/revenue.php'
)

usage() {
  cat <<'USAGE'
Usage:
  bash deploy_bma_local_time.sh --check
  bash deploy_bma_local_time.sh --deploy
  bash deploy_bma_local_time.sh --rollback /home/bbmmacom/bma-private/bma-time-backup.DATE.SUFFIX

--check verifies staged files and PHP syntax; does not change live files.
--deploy creates a private backup, installs all 23 staged files, and tests.
--rollback explicitly restores the 22 backed-up files and removes AppTime.php.
No database, credentials, .htaccess, bootstrap, or other files are changed.
USAGE
}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

target_path() {
  case "$1" in
    public/*) printf '%s/%s' "$PUBLIC" "${1#public/}" ;;
    *)        printf '%s/%s' "$PROJECT" "$1" ;;
  esac
}

check_prerequisites() {
  [[ -d "$PROJECT" && -d "$PUBLIC" && -d "$PRIVATE" && -d "$STAGE" ]] || die 'Expected project/public/private/stage directory is missing.'
  [[ -x "$PHP" ]] || die 'PHP 8.2 CLI not found.'
  [[ "$STAGE" == "$PRIVATE"/time-ready.* ]] || die 'Stage path is not in the approved private staging location.'
  [[ ! -L "$STAGE" ]] || die 'Refusing symlinked staging directory.'
  local file src target checked_php=0
  for file in "${FILES[@]}"; do
    src="$STAGE/$file"
    target="$(target_path "$file")"
    [[ -f "$src" && ! -L "$src" ]] || die "Missing or symlinked staged file: $file"
    if [[ "$file" == 'src/AppTime.php' ]]; then
      [[ ! -e "$target" && ! -L "$target" ]] || die 'AppTime.php already exists: stopping to avoid overwriting it.'
    else
      [[ -f "$target" && ! -L "$target" ]] || die "Live file missing or symlinked: $file"
    fi
    [[ -d "$(dirname "$target")" && -w "$(dirname "$target")" ]] || die "Target directory is not writable: $file"
    if grep -Eq '^(<<<<<<< |=======|>>>>>>> )' "$src"; then
      die "Unresolved conflict markers in staged file: $file"
    fi
    if [[ "$file" == *.php ]]; then
      "$PHP" -l "$src" >/dev/null || die "PHP lint failed: $file"
      checked_php=$((checked_php + 1))
    fi
  done
  echo "PASS: ${#FILES[@]} staged files present, $checked_php PHP files linted; no merge markers."
  if command -v node >/dev/null 2>&1; then
    node --check "$STAGE/public/assets/js/revenue.js"
    node --check "$STAGE/public/assets/js/member-profile.js"
    echo 'PASS: JavaScript syntax checks.'
  else
    echo 'INFO: Node is unavailable; JavaScript syntax was not locally checked.'
  fi
}

BACKUP=''
DEPLOYING=0

make_backup() {
  BACKUP="$(mktemp -d "$PRIVATE/bma-time-backup.$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")"
  chmod 700 "$BACKUP"
  mkdir -p "$BACKUP/original"
  local file target
  for file in "${FILES[@]}"; do
    printf '%s\n' "$file" >> "$BACKUP/manifest.txt"
    [[ "$file" == 'src/AppTime.php' ]] && continue
    target="$(target_path "$file")"
    mkdir -p "$BACKUP/original/$(dirname "$file")"
    cp -p -- "$target" "$BACKUP/original/$file"
    cmp -s -- "$target" "$BACKUP/original/$file" || die "Backup verification failed: $file"
  done
  printf 'new_file=src/AppTime.php\nsource_stage=%s\ncreated_utc=%s\n' \
    "$STAGE" "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "$BACKUP/metadata.txt"
  touch "$BACKUP/BACKUP_COMPLETE"
  echo "BACKUP SAVED: $BACKUP"
}

# Create a same-directory temp file and rename into place, retaining original mode.
install_file() {
  local src="$1" target="$2" tmp mode_ref="${3:-$2}"
  tmp="$(mktemp "$(dirname "$target")/.bma-time.XXXXXXXX")"
  if ! cp -- "$src" "$tmp"; then rm -f -- "$tmp"; return 1; fi
  if [[ -f "$mode_ref" ]]; then
    if ! chmod --reference="$mode_ref" "$tmp"; then rm -f -- "$tmp"; return 1; fi
  else
    if ! chmod 0644 "$tmp"; then rm -f -- "$tmp"; return 1; fi
  fi
  if ! mv -f -- "$tmp" "$target"; then rm -f -- "$tmp"; return 1; fi
}

restore_backup() {
  local dir="$1" file target original i
  [[ -f "$dir/BACKUP_COMPLETE" && -f "$dir/manifest.txt" ]] || { echo 'Backup incomplete; refusing restore.' >&2; return 1; }
  # Reverse deployment order, so database.php no longer references AppTime before removing it.
  for ((i=${#FILES[@]}-1; i>=0; i--)); do
    file="${FILES[i]}"
    target="$(target_path "$file")"
    if [[ "$file" == 'src/AppTime.php' ]]; then
      # New file: it did not exist when backup was made.
      rm -f -- "$target" || return 1
      continue
    fi
    original="$dir/original/$file"
    [[ -f "$original" && ! -L "$original" ]] || { echo "Missing backup: $file" >&2; return 1; }
    install_file "$original" "$target" "$original" || return 1
  done
  echo "RESTORED: $dir"
}

on_error() {
  local rc="$1"
  trap - ERR
  if [[ "$DEPLOYING" == 1 && -n "$BACKUP" ]]; then
    echo 'DEPLOYMENT FAILED: attempting automatic restoration of backed-up files.' >&2
    if ! restore_backup "$BACKUP"; then
      echo "URGENT: Automatic restoration incomplete. Retain backup: $BACKUP" >&2
    fi
  fi
  exit "$rc"
}
trap 'on_error $?' ERR

check_runtime() {
  "$PHP" -r '
    require "/home/bbmmacom/repositories/Stripe_app/src/AppTime.php";
    $actual = AppTime::display("2026-10-10 03:03:05");
    if ($actual !== "Oct 9, 2026, 11:03 PM EDT") {
        fwrite(STDERR, "Unexpected timezone display: $actual\n"); exit(1);
    }
    echo "PASS: UTC timestamp displays as Oct 9, 2026, 11:03 PM EDT\n";
  '
  # This check catches missing/mismatched PHP files after copy.
  local file target
  for file in "${FILES[@]}"; do
    [[ "$file" == *.php ]] || continue
    target="$(target_path "$file")"
    "$PHP" -l "$target" >/dev/null || return 1
  done
  echo 'PASS: Deployed PHP files linted.'
}

web_smoke() {
  command -v curl >/dev/null 2>&1 || { echo 'WARNING: curl unavailable; manually check the site.'; return 0; }
  local path code
  for path in '' 'login.php'; do
    code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 6 --max-time 15 "${URL}${path}")" || {
      echo "WARNING: Could not reach ${URL}${path} from the server; verify it in a browser."
      continue
    }
    case "$code" in
      200|301|302|303|307|308) echo "WEB: ${URL}${path} returned HTTP $code" ;;
      *) echo "WARNING: ${URL}${path} returned HTTP $code; check the browser and private application log." ;;
    esac
  done
}

case "${1:-}" in
  --check)
    [[ $# -eq 1 ]] || die '--check accepts no other arguments.'
    check_prerequisites
    ;;
  --deploy)
    [[ $# -eq 1 ]] || die '--deploy accepts no other arguments.'
    check_prerequisites
    make_backup
    echo 'Installing staged timezone changes (no other files touched)...'
    DEPLOYING=1
    # Install AppTime first and the loader second; then dependent repositories and public files.
    for file in "${FILES[@]}"; do
      install_file "$STAGE/$file" "$(target_path "$file")"
      echo "UPDATED: $file"
    done
    check_runtime
    DEPLOYING=0
    echo "DEPLOYED: Backup retained at $BACKUP"
    echo 'Checking public endpoints (availability check only)...'
    web_smoke
    echo 'NEXT: Sign in and verify Imports, Revenue, Membership, Demographics, and member profile. Hard-refresh JS.'
    ;;
  --rollback)
    [[ $# -eq 2 ]] || die 'Specify the exact private backup directory for rollback.'
    BACKUP="$2"
    [[ "$BACKUP" == "$PRIVATE"/bma-time-backup.* && -d "$BACKUP" && ! -L "$BACKUP" ]] || die 'Invalid rollback backup directory.'
    restore_backup "$BACKUP"
    echo 'Rollback complete. Check website and refresh browser.'
    ;;
  *) usage; exit 2 ;;
esac
