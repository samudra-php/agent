#!/usr/bin/env bash
set -euo pipefail

SCRIPT_SOURCE="${BASH_SOURCE[0]:-}"
AGENT_DIR=""

if [[ -n "${SCRIPT_SOURCE}" && -f "${SCRIPT_SOURCE}" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "${SCRIPT_SOURCE}")" && pwd)"
  AGENT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
fi

INSTALL_DIR="${SAMUDRA_INSTALL_DIR:-${HOME}/.local/bin}"
TARGET_PATH="${INSTALL_DIR}/samudra"

LOCAL_PHAR="${AGENT_DIR:+${AGENT_DIR}/dist/samudra.phar}"
SOURCE_FILE="${SAMUDRA_INSTALL_FILE:-}"
SOURCE_URL="${SAMUDRA_INSTALL_URL:-}"
DEFAULT_RELEASE_URL="https://github.com/samudra-php/agent/releases/latest/download/samudra.phar"

if ! command -v php >/dev/null 2>&1; then
  echo "php is required to run samudra.phar" >&2
  exit 1
fi

mkdir -p "${INSTALL_DIR}"

if [[ -n "${SOURCE_FILE}" ]]; then
  cp "${SOURCE_FILE}" "${TARGET_PATH}"
elif [[ -f "${LOCAL_PHAR}" ]]; then
  cp "${LOCAL_PHAR}" "${TARGET_PATH}"
elif [[ -n "${SOURCE_URL}" ]]; then
  curl -fsSL "${SOURCE_URL}" -o "${TARGET_PATH}"
else
  curl -fsSL "${DEFAULT_RELEASE_URL}" -o "${TARGET_PATH}"
fi

chmod +x "${TARGET_PATH}"

echo "Installed samudra to ${TARGET_PATH}"

case ":${PATH}:" in
  *":${INSTALL_DIR}:"*)
    echo "Command available: samudra --help"
    ;;
  *)
    echo "Add this directory to PATH to use 'samudra' globally:"
    echo "export PATH=\"${INSTALL_DIR}:\$PATH\""
    ;;
esac
