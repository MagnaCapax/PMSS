#!/usr/bin/env bash
# CI gate: fail if any customer-facing PHP (etc/skel/www/*) require_once /scripts/
#
# Rationale: customer PHP runs under per-user lighttpd as the customer UID,
# which cannot traverse the /scripts/ tree (750 root:root by design). Any
# `require_once '/scripts/...'` from a customer-facing file silently fails
# behind a file_exists() guard, producing fleet-wide invisible feature loss.
#
# See ADR 0016: docs/adr/0016-customer-php-tree-separation-from-operator-scripts.md
# See AGENTS.md: "INVIOLABLE — Customer-Facing PHP Tree Separation"
#
# Detection: grep for require/require_once/include strings starting with
# /scripts/ anywhere under etc/skel/www/. Any hit is a layering violation.

set -euo pipefail

ROOT_DIR="${ROOT_DIR:-$(cd "$(dirname "$0")/../.." && pwd)}"
CUSTOMER_TREE="$ROOT_DIR/etc/skel/www"

if [ ! -d "$CUSTOMER_TREE" ]; then
    echo "[customer-php-tree-isolation] $CUSTOMER_TREE does not exist; skipping" >&2
    exit 0
fi

# Match: require, require_once, include, include_once followed by a quoted
# path that starts with /scripts/. Allow whitespace between the keyword and
# the path. Case-sensitive (PHP is).
PATTERN="(require_once|require|include_once|include)[[:space:]]*\\(?[[:space:]]*['\"]/scripts/"

if hits=$(grep -RnE "$PATTERN" "$CUSTOMER_TREE" 2>/dev/null); then
    if [ -n "$hits" ]; then
        echo "[customer-php-tree-isolation] FAIL — customer PHP must not require /scripts/ paths" >&2
        echo "" >&2
        echo "Offending lines:" >&2
        echo "$hits" >&2
        echo "" >&2
        echo "Per ADR 0016, customer-facing PHP under etc/skel/www/ runs as the" >&2
        echo "customer UID and cannot traverse /scripts/ (operator-only 750 root:root)." >&2
        echo "Move the helper to etc/skel/www/ (Category-1 / 2) or split into" >&2
        echo "operator-write + customer-read (Category-3) per the ADR." >&2
        exit 1
    fi
fi

echo "[customer-php-tree-isolation] OK — no customer-PHP reaches into /scripts/" >&2
