#!/bin/bash
# WCI ULTRA-DEEP SYSTEM ANALYSIS

echo "WCI ULTRA-DEEP SYSTEM ANALYSIS"
echo "==============================="
echo "Start: $(date)"

ANALYZED=()
DEPENDENCIES=()

analyze_file() {
    local file="$1"
    local level="$2"
    
    if [[ $level -gt 10 ]]; then
        return
    fi
    
    for prev in "${ANALYZED[@]}"; do
        [[ "$prev" == "$file" ]] && return
    done
    
    ANALYZED+=("$file")
    
    local indent=$(printf "%*s" $((level*2)) "")
    echo "${indent}📄 L$level: $file"
    
    if [[ ! -f "$file" ]]; then
        echo "${indent}  ❌ NOT FOUND"
        return
    fi
    
    local size=$(wc -c < "$file" 2>/dev/null || echo "0")
    echo "${indent}  📊 ${size} bytes"
    
    # Find dependencies
    case "${file##*.}" in
        html)
            # CSS files
            grep -o 'href="[^"]*\.css"' "$file" 2>/dev/null | cut -d'"' -f2 | while read css; do
                if [[ -f "$css" ]]; then
                    echo "${indent}  ↳ CSS: $css"
                    DEPENDENCIES+=("$file → $css [CSS]")
                    analyze_file "$css" $((level + 1))
                fi
            done
            
            # JS files  
            grep -o 'src="[^"]*\.js"' "$file" 2>/dev/null | cut -d'"' -f2 | while read js; do
                if [[ -f "$js" ]]; then
                    echo "${indent}  ↳ JS: $js"
                    DEPENDENCIES+=("$file → $js [JS]")
                    analyze_file "$js" $((level + 1))
                fi
            done
            ;;
            
        js)
            # PHP API calls
            grep -o '[a-zA-Z0-9_-]*\.php' "$file" 2>/dev/null | sort -u | while read php; do
                if [[ -f "$php" ]]; then
                    echo "${indent}  ↳ API: $php"
                    DEPENDENCIES+=("$file → $php [API]")
                    analyze_file "$php" $((level + 1))
                fi
            done
            ;;
    esac
}

# Entry points
ENTRIES=("index.php" "login.html" "reservation.html" "ReservationDetails.html" "tisch-uebersicht.php")

echo ""
echo "🚀 Starting analysis..."

for entry in "${ENTRIES[@]}"; do
    if [[ -f "$entry" ]]; then
        echo ""
        echo "========== ENTRY: $entry =========="
        analyze_file "$entry" 0
    else
        echo "⚠️ Missing: $entry"
    fi
done

echo ""
echo "========== SUMMARY =========="
echo "Analyzed files: ${#ANALYZED[@]}"
echo ""

echo "📄 All analyzed files:"
for file in "${ANALYZED[@]}"; do
    if [[ -f "$file" ]]; then
        size=$(wc -c < "$file" 2>/dev/null)
        printf "  %-30s (%d bytes)\n" "$file" "$size"
    fi
done

echo ""
echo "🏝️ Orphan files (not analyzed):"
find . -name "*.html" -o -name "*.php" -o -name "*.js" -o -name "*.css" | grep -v "/\." | while read file; do
    file_clean="${file#./}"
    found=false
    for analyzed in "${ANALYZED[@]}"; do
        if [[ "$analyzed" == "$file_clean" ]]; then
            found=true
            break
        fi
    done
    
    if [[ "$found" == false ]]; then
        size=$(wc -c < "$file" 2>/dev/null)
        if [[ $size -gt 0 ]]; then
            printf "  %-30s (%d bytes)\n" "$file_clean" "$size"
        fi
    fi
done

echo ""
echo "✅ Analysis complete: $(date)"
