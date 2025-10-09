#!/bin/bash
# WCI FINAL ULTRA-DEEP SYSTEM ANALYSIS
# Zero tolerance for missed dependencies - COMPLETE VERSION

echo "🔬 WCI FINAL ULTRA-DEEP SYSTEM ANALYSIS"
echo "========================================"
echo "Start: $(date)"

ANALYZED_FILES=()
DEPENDENCY_GRAPH=()
ORPHAN_FILES=()
ACTIVE_FILES=()

declare -A FILE_SIZES
declare -A FILE_TYPES

file_already_analyzed() {
    local file="$1"
    for analyzed in "${ANALYZED_FILES[@]}"; do
        [[ "$analyzed" == "$file" ]] && return 0
    done
    return 1
}

mark_file_analyzed() {
    ANALYZED_FILES+=("$1")
    if [[ -f "$1" ]]; then
        FILE_SIZES["$1"]=$(wc -c < "$1" 2>/dev/null || echo "0")
        FILE_TYPES["$1"]="${1##*.}"
        ACTIVE_FILES+=("$1")
    fi
}

ultra_deep_analyze() {
    local file="$1"
    local level="$2" 
    local parent="$3"
    local context="$4"
    
    if [[ $level -gt 12 ]]; then
        return
    fi
    
    if file_already_analyzed "$file"; then
        return
    fi
    
    mark_file_analyzed "$file"
    
    local indent=$(printf "%*s" $((level*2)) "")
    echo "${indent}📄 L$level: $file ${context:+($context)}"
    
    if [[ -n "$parent" ]]; then
        DEPENDENCY_GRAPH+=("$parent → $file [$context]")
    fi
    
    if [[ ! -f "$file" ]]; then
        echo "${indent}  ❌ FILE NOT FOUND"
        return
    fi
    
    local file_size=${FILE_SIZES["$file"]}
    local file_lines=$(wc -l < "$file" 2>/dev/null || echo "0")
    echo "${indent}  📊 ${file_size}B, ${file_lines} lines"
    
    # COMPREHENSIVE DEPENDENCY EXTRACTION
    case "${file##*.}" in
        html)
            # CSS includes
            grep -o 'href="[^"]*\.css"' "$file" 2>/dev/null | cut -d'"' -f2 | while read css; do
                if [[ -f "$css" ]]; then
                    echo "${indent}  ↳ [CSS] $css"
                    ultra_deep_analyze "$css" $((level + 1)) "$file" "CSS"
                fi
            done
            
            # JS includes
            grep -o 'src="[^"]*\.js"' "$file" 2>/dev/null | cut -d'"' -f2 | while read js; do
                if [[ -f "$js" ]]; then
                    echo "${indent}  ↳ [JS] $js"
                    ultra_deep_analyze "$js" $((level + 1)) "$file" "JS"
                fi
            done
            
            # Form actions
            grep -o 'action="[^"]*\.php"' "$file" 2>/dev/null | cut -d'"' -f2 | while read php; do
                if [[ -f "$php" ]]; then
                    echo "${indent}  ↳ [FORM] $php"
                    ultra_deep_analyze "$php" $((level + 1)) "$file" "FORM"
                fi
            done
            
            # HTML links (href to .html/.php)
            grep -o 'href="[^"]*\.\(html\|php\)"' "$file" 2>/dev/null | cut -d'"' -f2 | cut -d'?' -f1 | while read link; do
                if [[ ! "$link" =~ ^https?: ]] && [[ -f "$link" ]]; then
                    echo "${indent}  ↳ [LINK] $link"
                    ultra_deep_analyze "$link" $((level + 1)) "$file" "LINK"
                fi
            done
            ;;
            
        js)
            # PHP API endpoints in JS
            grep -o '[a-zA-Z0-9_-]*\.php' "$file" 2>/dev/null | sort -u | while read php; do
                if [[ -f "$php" ]]; then
                    echo "${indent}  ↳ [API] $php"
                    ultra_deep_analyze "$php" $((level + 1)) "$file" "API"
                fi
            done
            
            # fetch() calls
            grep -E "fetch\s*\(\s*[\"'][^\"']*\.(php|html)[\"']" "$file" 2>/dev/null | sed -E "s/.*fetch\s*\(\s*[\"']([^\"']*)\.[^\"']*[\"'].*/\1/" | while read endpoint; do
                local full_endpoint="${endpoint}.php"
                if [[ -f "$full_endpoint" ]]; then
                    echo "${indent}  ↳ [FETCH] $full_endpoint"
                    ultra_deep_analyze "$full_endpoint" $((level + 1)) "$file" "FETCH"
                fi
            done
            
            # window.location assignments
            grep -E "window\.location[^=]*=[\"'][^\"']*\.(html|php)[\"']" "$file" 2>/dev/null | sed -E "s/.*[\"']([^\"']*\.(html|php))[\"'].*/\1/" | cut -d'?' -f1 | while read redirect; do
                if [[ ! "$redirect" =~ ^https?: ]] && [[ -f "$redirect" ]]; then
                    echo "${indent}  ↳ [REDIRECT] $redirect"
                    ultra_deep_analyze "$redirect" $((level + 1)) "$file" "REDIRECT"
                fi
            done
            ;;
            
        php)
            # require/include statements
            grep -E "(require_once|require|include_once|include)\s*[\"'][^\"']*[\"']" "$file" 2>/dev/null | sed -E "s/.*[\"']([^\"']*)[\"'].*/\1/" | while read include; do
                if [[ -f "$include" ]]; then
                    echo "${indent}  ↳ [INCLUDE] $include"
                    ultra_deep_analyze "$include" $((level + 1)) "$file" "INCLUDE"
                fi
            done
            
            # header Location redirects
            grep -E "header\s*\(\s*[\"']Location:\s*[^\"']*[\"']" "$file" 2>/dev/null | sed -E 's/.*Location:\s*([^"'"'"']*)["\'"'"'].*/\1/' | cut -d'?' -f1 | while read redirect; do
                if [[ ! "$redirect" =~ ^https?: ]] && [[ -f "$redirect" ]]; then
                    echo "${indent}  ↳ [REDIRECT] $redirect"
                    ultra_deep_analyze "$redirect" $((level + 1)) "$file" "REDIRECT"
                fi
            done
            ;;
            
        css)
            # @import statements
            grep -E '@import\s+["\'"'"']?[^"\'"'"';]*\.css' "$file" 2>/dev/null | sed -E 's/@import\s*["\'"'"']*([^"\'"'"']*\.css).*/\1/' | while read import; do
                if [[ -f "$import" ]]; then
                    echo "${indent}  ↳ [IMPORT] $import"
                    ultra_deep_analyze "$import" $((level + 1)) "$file" "IMPORT"
                fi
            done
            ;;
    esac
}

# COMPLETE SPECIAL ANALYSIS SECTION
complete_special_analysis() {
    echo ""
    echo "🎯 COMPLETE SPECIAL ANALYSIS"
    echo "============================"
    
    # ReservationDetails.html - ULTRA COMPLETE
    if [[ -f "ReservationDetails.html" ]]; then
        echo ""
        echo "🔍 ReservationDetails.html - COMPLETE BREAKDOWN"
        echo "File: $(wc -c < ReservationDetails.html)B, $(wc -l < ReservationDetails.html) lines"
        
        echo "  📄 ALL .php references:"
        grep -o '[a-zA-Z0-9_-]*\.php' "ReservationDetails.html" 2>/dev/null | sort -u | while read php; do
            if [[ -f "$php" ]]; then
                echo "    ✅ $php ($(wc -c < "$php")B)"
            else
                echo "    ❌ $php (MISSING)"
            fi
        done
        
        echo "  📄 ALL .js references:"
        grep -o '[a-zA-Z0-9/_-]*\.js' "ReservationDetails.html" 2>/dev/null | sort -u | while read js; do
            if [[ -f "$js" ]]; then
                echo "    ✅ $js ($(wc -c < "$js")B)"
            else
                echo "    ❌ $js (MISSING)"
            fi
        done
        
        echo "  📄 ALL .css references:"
        grep -o '[a-zA-Z0-9/_-]*\.css' "ReservationDetails.html" 2>/dev/null | sort -u | while read css; do
            if [[ -f "$css" ]]; then
                echo "    ✅ $css ($(wc -c < "$css")B)"
            else
                echo "    ❌ $css (MISSING)"
            fi
        done
    fi
    
    # ReservationDetails.js - COMPLETE API MAPPING
    if [[ -f "ReservationDetails.js" ]]; then
        echo ""
        echo "🔍 ReservationDetails.js - COMPLETE API MAPPING"
        echo "File: $(wc -c < ReservationDetails.js)B, $(wc -l < ReservationDetails.js) lines"
        
        echo "  🔗 ALL API endpoints:"
        grep -o '[a-zA-Z0-9_-]*\.php' "ReservationDetails.js" 2>/dev/null | sort -u | while read api; do
            if [[ -f "$api" ]]; then
                echo "    ✅ $api ($(wc -c < "$api")B, $(wc -l < "$api") lines)"
            else
                echo "    ❌ $api (MISSING)"
            fi
        done
        
        echo "  📞 Fetch calls:"
        grep -n "fetch(" "ReservationDetails.js" 2>/dev/null | head -5
        
        echo "  🌐 HttpUtils calls:"
        grep -n "HttpUtils\." "ReservationDetails.js" 2>/dev/null | head -5
    fi
    
    # reservation.js - MASSIVE FILE BREAKDOWN
    if [[ -f "reservation.js" ]]; then
        echo ""
        echo "🔍 reservation.js - MASSIVE FILE BREAKDOWN"
        echo "File: $(wc -c < reservation.js)B, $(wc -l < reservation.js) lines"
        
        echo "  🔗 ALL API dependencies ($(grep -o '[a-zA-Z0-9_-]*\.php' reservation.js 2>/dev/null | sort -u | wc -l) total):"
        grep -o '[a-zA-Z0-9_-]*\.php' "reservation.js" 2>/dev/null | sort -u | while read api; do
            if [[ -f "$api" ]]; then
                echo "    ✅ $api ($(wc -c < "$api")B, $(wc -l < "$api") lines)"
            else
                echo "    ❌ $api (MISSING)"
            fi
        done
    fi
    
    # HP arrangements analysis
    echo ""
    echo "🏢 HP ARRANGEMENTS SYSTEM:"
    hp_files=(get-hp-arrangements.php get-hp-arrangements-header.php get-hp-arrangements-table.php save-hp-arrangements.php save-hp-arrangements-table.php)
    for hp_file in "${hp_files[@]}"; do
        if [[ -f "$hp_file" ]]; then
            echo "  ✅ $hp_file ($(wc -c < "$hp_file")B)"
        else
            echo "  ❌ $hp_file (MISSING)"
        fi
    done
    
    # Navigation system analysis
    echo ""
    echo "🧭 NAVIGATION SYSTEM:"
    nav_files=(js/navigation.js css/navigation.css css/navigation-integration.css)
    for nav_file in "${nav_files[@]}"; do
        if [[ -f "$nav_file" ]]; then
            echo "  ✅ $nav_file ($(wc -c < "$nav_file")B)"
        else
            echo "  ❌ $nav_file (MISSING)"
        fi
    done
}

# ULTIMATE ORPHAN DETECTION
ultimate_orphan_detection() {
    echo ""
    echo "🏝️ ULTIMATE ORPHAN FILE DETECTION"
    echo "=================================="
    
    # Find ALL files
    all_files=($(find . -name "*.html" -o -name "*.php" -o -name "*.js" -o -name "*.css" | grep -v "/\." | sort))
    
    echo "📊 Total files found: ${#all_files[@]}"
    echo "📊 Active files (analyzed): ${#ACTIVE_FILES[@]}"
    
    orphan_count=0
    potentially_active=0
    empty_files=0
    
    for file in "${all_files[@]}"; do
        file_clean="${file#./}"
        found=false
        
        for analyzed in "${ACTIVE_FILES[@]}"; do
            if [[ "$analyzed" == "$file_clean" ]]; then
                found=true
                break
            fi
        done
        
        if [[ "$found" == false ]]; then
            size=$(wc -c < "$file" 2>/dev/null || echo "0")
            lines=$(wc -l < "$file" 2>/dev/null || echo "0")
            
            ORPHAN_FILES+=("$file_clean")
            ((orphan_count++))
            
            # Classify orphans
            if [[ $size -eq 0 ]]; then
                echo "  🗑️  $file_clean (EMPTY FILE)"
                ((empty_files++))
            elif [[ "${file##*.}" == "php" ]] && grep -q -E "(function|class|include|require|\\\$)" "$file" 2>/dev/null; then
                echo "  🚨 $file_clean (${size}B, ${lines} lines) - POTENTIALLY ACTIVE PHP"
                ((potentially_active++))
            elif [[ "${file##*.}" == "js" ]] && grep -q -E "(function|const|let|var)" "$file" 2>/dev/null && [[ $size -gt 100 ]]; then
                echo "  🚨 $file_clean (${size}B, ${lines} lines) - POTENTIALLY ACTIVE JS"
                ((potentially_active++))
            elif [[ "${file##*.}" == "html" ]] && grep -q -E "(<script|<link|<form)" "$file" 2>/dev/null; then
                echo "  🚨 $file_clean (${size}B, ${lines} lines) - POTENTIALLY ACTIVE HTML"
                ((potentially_active++))
            elif [[ $size -gt 10000 ]]; then
                echo "  ⚠️  $file_clean (${size}B, ${lines} lines) - LARGE ORPHAN"
            else
                echo "  🏝️  $file_clean (${size}B, ${lines} lines) - ORPHAN"
            fi
        fi
    done
    
    echo ""
    echo "📊 ORPHAN SUMMARY:"
    echo "  Total orphans: $orphan_count"
    echo "  Potentially active: $potentially_active"
    echo "  Empty files: $empty_files"
}

# ENTRY POINTS - COMPREHENSIVE LIST
ENTRY_POINTS=(
    "index.php"
    "login.html" 
    "reservation.html"
    "ReservationDetails.html"
    "statistiken.html"
    "tisch-uebersicht.php"
    "tisch-uebersicht-resid.php"
    "reservierungen.html"
    "GastDetail.html"
    "zp/timeline-unified.html"
    "transport.html"
    "navigation-demo.html"
)

echo ""
echo "🚀 STARTING ULTRA-DEEP ANALYSIS..."
echo "Entry points: ${#ENTRY_POINTS[@]}"

# MAIN ANALYSIS LOOP
for entry in "${ENTRY_POINTS[@]}"; do
    if [[ -f "$entry" ]]; then
        echo ""
        echo "=========================================="
        echo "🎯 ENTRY POINT: $entry"
        echo "=========================================="
        ultra_deep_analyze "$entry" 0 "" "ENTRY"
    else
        echo "⚠️  Entry point missing: $entry"
    fi
done

# SPECIAL ANALYSES
complete_special_analysis
ultimate_orphan_detection

echo ""
echo "=========================================="
echo "🎯 FINAL ULTRA-DEEP SUMMARY" 
echo "=========================================="
echo "📊 Analysis completed: $(date)"
echo ""
echo "📈 STATISTICS:"
echo "  Total files analyzed: ${#ANALYZED_FILES[@]}"
echo "  Active files found: ${#ACTIVE_FILES[@]}"
echo "  Dependencies mapped: ${#DEPENDENCY_GRAPH[@]}"
echo "  Orphan files: ${#ORPHAN_FILES[@]}"

echo ""
echo "📄 ACTIVE FILES (by size):"
printf '%s\n' "${ACTIVE_FILES[@]}" | while read file; do
    if [[ -f "$file" ]]; then
        size=${FILE_SIZES["$file"]}
        lines=$(wc -l < "$file" 2>/dev/null)
        echo "$size $file $lines"
    fi
done | sort -nr | head -20 | while read size file lines; do
    printf "  %-40s %8dB %5d lines [%s]\n" "$file" "$size" "$lines" "${FILE_TYPES["$file"]}"
done

echo ""
echo "🔗 TOP DEPENDENCY CHAINS:"
printf '%s\n' "${DEPENDENCY_GRAPH[@]}" | sort -u | head -15

echo ""
echo "✅ ULTRA-DEEP ANALYSIS COMPLETE!"
echo "🎯 $(date)"
