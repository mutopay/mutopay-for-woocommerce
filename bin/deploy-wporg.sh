#!/usr/bin/env bash
#
# Deploy MutoPay for WooCommerce to the WordPress.org plugin SVN repo.
#
# What it does:
#   1. Checks out (or updates) the WP.org SVN repo into a build dir.
#   2. Syncs the current plugin files into trunk/ (strict include list, no dev files).
#   3. Copies trunk/ into tags/<version> (version read from readme.txt "Stable tag").
#   4. Copies the plugin icon into the SVN-root assets/ for the directory listing.
#   5. Stages adds/deletes, then PRINTS the commit command for you to run.
#
# It deliberately does NOT run `svn commit`: that needs your WordPress.org SVN
# password (set under Account & Security on your wordpress.org profile, NOT your
# wp.org login password). Run the printed command yourself so you type the
# password interactively.
#
# Usage:
#   bin/deploy-wporg.sh
#
set -euo pipefail

SLUG="mutopay-for-woocommerce"
SVN_USER="mutopay"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${SVN_BUILD_DIR:-$HOME/wporg-svn/${SLUG}}"

command -v svn >/dev/null 2>&1 || { echo "ERROR: svn is not installed (brew install svn)"; exit 1; }
command -v rsync >/dev/null 2>&1 || { echo "ERROR: rsync is not installed"; exit 1; }

# Version = Stable tag in readme.txt (the canonical source for WP.org).
VERSION="$(grep -iE '^Stable tag:' "${REPO_DIR}/readme.txt" | sed -E 's/.*:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "${VERSION}" ] || { echo "ERROR: could not read Stable tag from readme.txt"; exit 1; }

# Sanity: header version + constant must match the stable tag.
HEADER_VER="$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "${REPO_DIR}/${SLUG}.php" | sed -E 's/.*:[[:space:]]*//' | tr -d '[:space:]')"
if [ "${HEADER_VER}" != "${VERSION}" ]; then
  echo "ERROR: plugin header Version (${HEADER_VER}) != readme Stable tag (${VERSION}). Aborting."
  exit 1
fi

echo "==> Deploying ${SLUG} version ${VERSION}"
echo "    SVN:   ${SVN_URL}"
echo "    Build: ${BUILD_DIR}"
echo

# 1. Checkout or update.
if [ -d "${BUILD_DIR}/.svn" ]; then
  echo "==> Updating existing SVN checkout"
  svn update "${BUILD_DIR}"
else
  echo "==> Checking out SVN repo (you may be prompted for your WP.org SVN password)"
  mkdir -p "$(dirname "${BUILD_DIR}")"
  svn checkout "${SVN_URL}" "${BUILD_DIR}"
fi

mkdir -p "${BUILD_DIR}/trunk" "${BUILD_DIR}/assets"

# 2. Sync plugin files into trunk/. Strict include list: only what ships.
echo "==> Syncing plugin files into trunk/"
rsync -a --delete \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.gitignore' \
  --exclude='.claude' \
  --exclude='.svn' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='node_modules' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='bin' \
  --exclude='*.zip' \
  --exclude='.wordpress-org' \
  --exclude='README.md' \
  --exclude='TESTING.md' \
  --exclude='.wp-env.json' \
  --exclude='.wp-env.override.json' \
  --exclude='.wp-env-uploads' \
  "${REPO_DIR}/" "${BUILD_DIR}/trunk/"

# 3. (Re)build the version tag from trunk.
echo "==> Building tags/${VERSION} from trunk/"
rm -rf "${BUILD_DIR}/tags/${VERSION}"
mkdir -p "${BUILD_DIR}/tags"
cp -R "${BUILD_DIR}/trunk" "${BUILD_DIR}/tags/${VERSION}"
# Drop any .svn metadata copied along (cp -R of a checked-out trunk can carry it).
find "${BUILD_DIR}/tags/${VERSION}" -name '.svn' -type d -prune -exec rm -rf {} + 2>/dev/null || true

# 4. Directory-listing graphics (icon + banners) for the public WP.org page.
#    These live at the SVN ROOT assets/, NOT inside trunk. Source of truth is
#    .wordpress-org/assets/ in this repo (regenerate via .wordpress-org/src/*.html).
if [ -d "${REPO_DIR}/.wordpress-org/assets" ]; then
  rsync -a "${REPO_DIR}/.wordpress-org/assets/" "${BUILD_DIR}/assets/"
  echo "==> Synced listing graphics into assets/ (icon + banners)"
fi

# 5. Stage adds/deletes for SVN.
echo "==> Staging adds/deletes"
cd "${BUILD_DIR}"
# Add anything unversioned.
svn status | awk '/^\?/ {print $2}' | xargs -I{} svn add "{}" >/dev/null 2>&1 || true
# Remove anything missing (deleted upstream).
svn status | awk '/^\!/ {print $2}' | xargs -I{} svn rm "{}" >/dev/null 2>&1 || true

echo
echo "==> Staged changes:"
svn status
echo
echo "============================================================"
echo "Review the staged changes above. When ready, COMMIT with:"
echo
echo "  cd \"${BUILD_DIR}\" && svn commit -m \"Release ${VERSION}\" --username ${SVN_USER}"
echo
echo "You'll be prompted for your WordPress.org SVN password"
echo "(Account & Security on your wp.org profile, not your login password)."
echo "============================================================"
