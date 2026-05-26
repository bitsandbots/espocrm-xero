#!/usr/bin/env bash

set -euo pipefail

# Package the EspoCRM Xero module into a versioned ZIP archive.
# Usage: ./scripts/release.sh [--version X.Y.Z] [--espo-path PATH] [--skip-tests] [--skip-transpile]
#
# --espo-path      Path to an EspoCRM installation (needed for tests and transpilation).
#                  Defaults to $HOME/espocrm if present.
# --skip-tests     Skip PHPUnit test run.
# --skip-transpile Skip JS transpilation step.
#
# Requires: zip, php, node (unless --skip-transpile)

##############################################################################
# COLOR & OUTPUT UTILITIES
##############################################################################

_green()  { echo -e "\033[32m$*\033[0m"; }
_yellow() { echo -e "\033[33m$*\033[0m"; }
_red()    { echo -e "\033[31m$*\033[0m"; }
_blue()   { echo -e "\033[34m$*\033[0m"; }

##############################################################################
# DEPENDENCY CHECKS
##############################################################################

check_command() {
  if ! command -v "$1" &> /dev/null; then
    _red "ERROR: Required command not found: $1"
    exit 1
  fi
}

_blue "Checking dependencies..."
check_command "zip"
check_command "php"
_green "Core dependencies available"

##############################################################################
# ARGUMENT PARSING
##############################################################################

VERSION=""
ESPO_PATH="${HOME}/espocrm"
SKIP_TESTS=false
SKIP_TRANSPILE=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version)
      VERSION="$2"
      shift 2
      ;;
    --espo-path)
      ESPO_PATH="$2"
      shift 2
      ;;
    --skip-tests)
      SKIP_TESTS=true
      shift
      ;;
    --skip-transpile)
      SKIP_TRANSPILE=true
      shift
      ;;
    *)
      _red "ERROR: Unknown option: $1"
      exit 1
      ;;
  esac
done

##############################################################################
# VERSION RESOLUTION
##############################################################################

if [[ -z "$VERSION" ]]; then
  if git describe --tags --abbrev=0 &>/dev/null; then
    VERSION=$(git describe --tags --abbrev=0 | sed 's/^v//')
  else
    VERSION="0.0.0-dev"
  fi
fi

_green "Release version: $VERSION"

##############################################################################
# ESPO PATH VALIDATION
##############################################################################

if [[ ! -d "$ESPO_PATH" ]]; then
  _red "ERROR: EspoCRM path does not exist: $ESPO_PATH"
  _yellow "Pass --espo-path /path/to/espocrm or set ESPO_PATH environment variable."
  exit 1
fi

ESPO_PATH="$(cd "$ESPO_PATH" && pwd)"
_green "EspoCRM path: $ESPO_PATH"

##############################################################################
# SETUP & CLEANUP
##############################################################################

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGING_DIR="${PROJECT_ROOT}/.release-staging-$$"
RELEASES_DIR="${PROJECT_ROOT}/releases"

cleanup() {
  _blue "Cleaning up staging..."
  rm -rf "$STAGING_DIR"
}
trap cleanup EXIT

mkdir -p "$RELEASES_DIR"

##############################################################################
# TRANSPILE CLIENT CODE
##############################################################################

if [[ "$SKIP_TRANSPILE" == false ]]; then
  _blue "Transpiling client code..."
  check_command "node"
  if node "${ESPO_PATH}/js/transpile.js"; then
    _green "Transpilation successful"
  else
    _red "ERROR: Transpilation failed"
    exit 1
  fi
else
  _yellow "Skipping transpilation (--skip-transpile)"
fi

##############################################################################
# RUN TESTS
##############################################################################

if [[ "$SKIP_TESTS" == false ]]; then
  _blue "Running Xero unit tests..."

  PHPUNIT="${ESPO_PATH}/vendor/bin/phpunit"

  if [[ ! -f "$PHPUNIT" ]]; then
    _red "ERROR: phpunit not found at $PHPUNIT"
    _yellow "Run 'composer install' in $ESPO_PATH first."
    exit 1
  fi

  ESPO_PATH="$ESPO_PATH" php "$PHPUNIT" \
    --configuration "${PROJECT_ROOT}/phpunit.xml" \
    --no-coverage 2>&1 | tee /tmp/phpunit-xero-output.log
  PHPUNIT_EXIT="${PIPESTATUS[0]}"

  if [[ "$PHPUNIT_EXIT" -eq 0 ]]; then
    _green "PHP tests passed"
  else
    _red "ERROR: PHP tests failed (exit $PHPUNIT_EXIT)"
    tail -30 /tmp/phpunit-xero-output.log
    exit 1
  fi
else
  _yellow "Skipping tests (--skip-tests)"
fi

##############################################################################
# STAGE FILES
##############################################################################

_blue "Staging files..."
mkdir -p "$STAGING_DIR"

_green "  Staging server module..."
mkdir -p "$STAGING_DIR/custom/Espo/Modules"
cp -r "${PROJECT_ROOT}/custom/Espo/Modules/Xero" \
  "$STAGING_DIR/custom/Espo/Modules/Xero"

_green "  Staging client module..."
mkdir -p "$STAGING_DIR/client/custom/modules"
cp -r "${PROJECT_ROOT}/client/custom/modules/xero" \
  "$STAGING_DIR/client/custom/modules/xero"

_green "  Staging install script..."
mkdir -p "$STAGING_DIR/scripts"
cp "${PROJECT_ROOT}/scripts/install.sh" "$STAGING_DIR/scripts/install.sh"
chmod +x "$STAGING_DIR/scripts/install.sh"

if [[ -d "${PROJECT_ROOT}/docs" ]]; then
  _green "  Staging documentation..."
  cp -r "${PROJECT_ROOT}/docs" "$STAGING_DIR/docs"
fi

if [[ -f "${PROJECT_ROOT}/README.md" ]]; then
  _green "  Staging README..."
  cp "${PROJECT_ROOT}/README.md" "$STAGING_DIR/README.md"
fi

if [[ -f "${PROJECT_ROOT}/LICENSE" ]]; then
  _green "  Staging LICENSE..."
  cp "${PROJECT_ROOT}/LICENSE" "$STAGING_DIR/LICENSE"
fi

# Strip dev-only artifacts
find "$STAGING_DIR" -type f \( \
  -name "*.test.js" \
  -o -name "*.test.ts" \
  -o -name "*.test.php" \
  -o -name "*.map" \
  -o -name ".gitignore" \
  \) -delete

find "$STAGING_DIR" -type d \( \
  -name "node_modules" \
  -o -name ".git" \
  \) -exec rm -rf {} + 2>/dev/null || true

_green "Files staged"

##############################################################################
# CREATE ZIP ARCHIVE
##############################################################################

_blue "Creating release archive..."

ZIP_FILE="${RELEASES_DIR}/espocrm-xero-v${VERSION}.zip"
rm -f "$ZIP_FILE"

cd "$STAGING_DIR"
zip -r "$ZIP_FILE" . > /dev/null 2>&1
cd "$PROJECT_ROOT"

if [[ ! -f "$ZIP_FILE" ]]; then
  _red "ERROR: Failed to create ZIP archive"
  exit 1
fi

_green "Archive created: $ZIP_FILE"

##############################################################################
# CHECKSUM & FINAL OUTPUT
##############################################################################

CHECKSUM=$(sha256sum "$ZIP_FILE" | awk '{print $1}')

# Write checksum file alongside the ZIP
CHECKSUM_FILE="${RELEASES_DIR}/espocrm-xero-v${VERSION}.sha256"
echo "$CHECKSUM  $(basename "$ZIP_FILE")" > "$CHECKSUM_FILE"
_green "Checksum file: $CHECKSUM_FILE"

_green "Release package ready!"
echo ""
_blue "Package details:"
echo "  File:      $ZIP_FILE"
echo "  Version:   $VERSION"
echo "  Checksum:  $CHECKSUM"
echo ""
_blue "Deployment:"
_yellow "scp $ZIP_FILE user@server:/tmp/"
_yellow "cd /path/to/espocrm && unzip -o /tmp/$(basename "$ZIP_FILE")"
_yellow "./scripts/install.sh --espo-path /path/to/espocrm"
echo ""
