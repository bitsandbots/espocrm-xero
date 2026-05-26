#!/usr/bin/env bash

set -euo pipefail

# Install the EspoCRM Xero module onto an existing EspoCRM instance.
# Usage: ./scripts/install.sh --espo-path PATH

##############################################################################
# COLOR & OUTPUT UTILITIES
##############################################################################

_green()  { echo -e "\033[32m$*\033[0m"; }
_yellow() { echo -e "\033[33m$*\033[0m"; }
_red()    { echo -e "\033[31m$*\033[0m"; }
_blue()   { echo -e "\033[34m$*\033[0m"; }

##############################################################################
# ARGUMENT PARSING
##############################################################################

ESPO_PATH=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --espo-path)
      ESPO_PATH="$2"
      shift 2
      ;;
    *)
      _red "ERROR: Unknown option: $1"
      echo "Usage: $0 --espo-path /path/to/espocrm"
      exit 1
      ;;
  esac
done

if [[ -z "$ESPO_PATH" ]]; then
  _red "ERROR: --espo-path is required"
  echo "Usage: $0 --espo-path /path/to/espocrm"
  exit 1
fi

##############################################################################
# VALIDATION
##############################################################################

ESPO_PATH="$(cd "$ESPO_PATH" 2>/dev/null && pwd)" || {
  _red "ERROR: EspoCRM path does not exist: $ESPO_PATH"
  exit 1
}

if [[ ! -f "$ESPO_PATH/data/config.php" ]]; then
  _red "ERROR: EspoCRM config not found at $ESPO_PATH/data/config.php"
  exit 1
fi

_green "Found EspoCRM at: $ESPO_PATH"

CONFIG_OWNER=$(stat -c "%U" "$ESPO_PATH/data/config.php")
CURRENT_USER=$(whoami)

if [[ "$CURRENT_USER" != "$CONFIG_OWNER" ]]; then
  _yellow "WARNING: Running as '$CURRENT_USER' but config.php is owned by '$CONFIG_OWNER'"
  _yellow "This may cause permission issues during module installation."
fi

##############################################################################
# DETERMINE SOURCE ROOT
##############################################################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

##############################################################################
# COPY MODULE FILES
##############################################################################

copy_module() {
  local src="$1"
  local dst="$2"
  local name="$3"

  if [[ ! -d "$src" ]]; then
    _red "ERROR: Source not found: $src"
    return 1
  fi

  local dst_resolved
  dst_resolved="$(cd "$(dirname "$dst")" && pwd)/$(basename "$dst")"
  local src_resolved
  src_resolved="$(cd "$src" && pwd)"

  if [[ "$src_resolved" == "$dst_resolved" ]]; then
    _green "  $name already in place"
    return 0
  fi

  _green "  Copying $name..."
  cp -r "$src" "$(dirname "$dst")/"
}

_blue "Installing Xero module..."

copy_module \
  "${SOURCE_ROOT}/custom/Espo/Modules/Xero" \
  "${ESPO_PATH}/custom/Espo/Modules/Xero" \
  "Xero server module" || exit 1

copy_module \
  "${SOURCE_ROOT}/client/custom/modules/xero" \
  "${ESPO_PATH}/client/custom/modules/xero" \
  "Xero client module" || exit 1

##############################################################################
# REBUILD & CACHE CLEAR
##############################################################################

_blue "Running system rebuild..."

cd "$ESPO_PATH"

if php command.php rebuild 2>&1 | grep -q "Rebuild succeeded"; then
  _green "  Rebuild succeeded"
else
  _red "ERROR: Rebuild failed — check EspoCRM logs for details"
  exit 1
fi

php command.php clear-cache
_green "  Cache cleared"

##############################################################################
# POST-INSTALL INSTRUCTIONS
##############################################################################

_green "Xero module installed successfully!"
echo ""
_blue "Post-installation steps:"
echo ""
echo "1. Configure cron job (as root or with sudo):"
_yellow "   * * * * * $CONFIG_OWNER php $ESPO_PATH/cron.php > /dev/null 2>&1"
echo ""
echo "2. Enter Xero credentials:"
_yellow "   Admin > Integrations > Xero"
_yellow "   Enter Client ID and Client Secret, then click Save and Connect."
_yellow "   Redirect URI to register in your Xero developer app:"
_yellow "     {siteUrl}?entryPoint=XeroOauthCallback"
echo ""
echo "3. Enable scheduled jobs:"
_yellow "   Admin > Scheduled Jobs — enable:"
echo "     - SyncFromXero       (nightly pull of Xero contacts and payments)"
echo "     - ReconcileXero      (nightly reconciliation, run 15 min after sync)"
echo ""
echo "4. HTTPS requirement:"
_yellow "   Xero OAuth requires HTTPS."
_yellow "   Ensure Admin > System > Site URL uses https://"
echo ""
