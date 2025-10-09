#!/bin/bash
# Wiederherstellungs-Script
# WARNUNG: Kopiert ALLE Dateien zurück!

echo "WARNUNG: Dies stellt ALLE verschobenen Dateien wieder her!"
read -p "Wirklich fortfahren? [j/N] " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Jj]$ ]]; then
    echo "Abgebrochen."
    exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

for category_dir in "$SCRIPT_DIR"/*/; do
    if [ -d "$category_dir" ]; then
        echo "Stelle wieder her: $(basename "$category_dir")"
        cp -rv "$category_dir"* . 2>/dev/null || true
    fi
done

echo "Wiederherstellung abgeschlossen!"
