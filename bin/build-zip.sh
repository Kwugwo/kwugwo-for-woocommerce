#!/usr/bin/env bash
# Build the release zip for WordPress.org: build/kwugwo-for-woocommerce.zip
# Copies the plugin without the paths listed in .distignore.
set -euo pipefail

cd "$(dirname "$0")/.."
slug="kwugwo-for-woocommerce"

rm -rf build
mkdir -p "build/$slug"
tar -cf - --anchored --exclude-from=<(sed -e 's#^/##' -e 's#^#./#' .distignore) --exclude=./build . | tar -xf - -C "build/$slug"
if command -v zip >/dev/null; then
	(cd build && zip -qr "$slug.zip" "$slug")
else
	(cd build && python3 -m zipfile -c "$slug.zip" "$slug")
fi
echo "Built build/$slug.zip"
