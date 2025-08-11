#!/bin/bash
# WCI Complete Recursive Dependency Analysis
# Analyzes ALL dependencies recursively until the very last level

echo "🔍 VOLLSTÄNDIGE REKURSIVE SYSTEMANALYSE"
echo "========================================"
echo "Startzeit: $(date)"
echo ""

ANALYZED_FILES=()
DEPENDENCY_GRAPH=()
MAX_DEPTH=10
OUTPUT_FILE="recursive-dependency-analysis.txt"

# Function to check if file was already analyzed
file_already_analyzed() {
    local file="$1"
    for analyzed in "${ANALYZED_FILES[@]}"; do
        if [[ "$analyzed" == "$file" ]]; then
            return 0
        fi
    done
    return 1
}

# Function to add to analyzed files
mark_file_analyzed() {
    ANALYZED_FILES+=("$1")
}

# Function to extract dependencies from different file types
extract_dependencies() {
    local file="$1"
    local level="$2"
    local dependencies=()
    
    if [[ ! -f "$file" ]]; then
        return
    fi
    
    case "${file##*.}" in
        html)
            # HTML: Extract CSS, JS, and direct links
            # CSS links
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:CSS")
            done < <(grep -oE 'href="[^"]*\.css"' "$file" 2>/dev/null | sed 's/href="//g' | sed 's/"//g')
            
            # JS includes
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:JS")
            done < <(grep -oE 'src="[^"]*\.js"' "$file" 2>/dev/null | sed 's/src="//g' | sed 's/"//g')
            
            # Direct HTML links
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && ! "$dep" =~ ^\? && -f "$dep" ]] && dependencies+=("$dep:LINK")
            done < <(grep -oE 'href="[^"]*\.html"' "$file" 2>/dev/null | sed 's/href="//g' | sed 's/"//g')
            
            # Form actions
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:FORM")
            done < <(grep -oE 'action="[^"]*\.php"' "$file" 2>/dev/null | sed 's/action="//g' | sed 's/"//g')
            ;;
            
        js)
            # JavaScript: Extract fetch calls, AJAX, and dynamic loads
            # fetch() calls
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:FETCH")
            done < <(grep -oE "fetch\(['\"][^'\"]*\.(php|html)['\"]" "$file" 2>/dev/null | sed "s/fetch(['\"]//" | sed "s/['\"].*//" )
            
            # HttpUtils requests
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:HTTP_UTIL")
            done < <(grep -oE "HttpUtils\.[^(]*\(['\"][^'\"]*\.(php|html)['\"]" "$file" 2>/dev/null | sed "s/.*['\"]\\([^'\"]*\\)['\"].*/\\1/")
            
            # Dynamic script loading
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:DYNAMIC_JS")
            done < <(grep -oE "\.src\s*=\s*['\"][^'\"]*\.js['\"]" "$file" 2>/dev/null | sed "s/.*['\"]//g" | sed "s/['\"].*//" )
            
            # window.location assignments
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && ! "$dep" =~ ^\? && -f "$dep" ]] && dependencies+=("$dep:REDIRECT")
            done < <(grep -oE "window\.location[^=]*=\s*['\"][^'\"]*\.(html|php)" "$file" 2>/dev/null | sed "s/.*['\"]//g" | sed "s/['\"].*//" )
            ;;
            
        php)
            # PHP: Extract includes, requires, and internal redirects
            # require/include statements
            while read -r dep; do
                [[ -n "$dep" && -f "$dep" ]] && dependencies+=("$dep:REQUIRE")
            done < <(grep -oE "(require_once|require|include_once|include)\s*['\"][^'\"]*['\"]" "$file" 2>/dev/null | sed "s/.*['\"]//g" | sed "s/['\"].*//" )
            
            # header redirects
            while read -r dep; do
                [[ -n "$dep" && ! "$dep" =~ ^https?:// && -f "$dep" ]] && dependencies+=("$dep:REDIRECT")
            done < <(grep -oE "header\(['\"]Location:\s*[^'\"]*['\"]" "$file" 2>/dev/null | sed "s/.*Location:\s*//g" | sed "s/['\"].*//" | sed "s/\?.*//" )
            ;;
            
        css)
            # CSS: Extract @import and background images
            while read -r dep; do
                [[ -n "$dep" && -f "$dep" ]] && dependencies+=("$dep:IMPORT")
            done < <(grep -oE "@import\s+['\"]?[^'\";\s]*\.css" "$file" 2>/dev/null | sed "s/@import\s*['\"]*//" | sed "s/['\"].*//" )
            ;;
    esac
    
    echo "${dependencies[@]}"
}

# Recursive dependency analysis
analyze_dependencies_recursive() {
    local file="$1"
    local level="$2"
    local parent="$3"
    
    if [[ $level -gt $MAX_DEPTH ]]; then
        echo "$(printf "%*s" $((level*2)) "")⚠️  Max depth reached for: $file"
        return
    fi
    
    if file_already_analyzed "$file"; then
        echo "$(printf "%*s" $((level*2)) "")🔄 Already analyzed: $file"
        return
    fi
    
    mark_file_analyzed "$file"
    
    local indent=$(printf "%*s" $((level*2)) "")
    echo "${indent}📄 Level $level: $file"
    
    if [[ -n "$parent" ]]; then
        DEPENDENCY_GRAPH+=("$parent → $file")
    fi
    
    # Check file existence and type
    if [[ ! -f "$file" ]]; then
        echo "${indent}  ❌ File not found"
        return
    fi
    
    # Determine file status
    local file_size=$(wc -c < "$file" 2>/dev/null || echo "0")
    local last_modified=$(stat -c %Y "$file" 2>/dev/null || echo "0")
    echo "${indent}  📊 Size: ${file_size} bytes, Modified: $(date -d @${last_modified} 2>/dev/null || echo 'unknown')"
    
    # Extract dependencies
    local dependencies=($(extract_dependencies "$file" "$level"))
    
    if [[ ${#dependencies[@]} -eq 0 ]]; then
        echo "${indent}  📝 No dependencies found"
        return
    fi
    
    # Process each dependency
    for dep_info in "${dependencies[@]}"; do
        local dep_file="${dep_info%:*}"
        local dep_type="${dep_info#*:}"
        
        echo "${indent}  ↳ [$dep_type] $dep_file"
        
        # Recursively analyze this dependency
        analyze_dependencies_recursive "$dep_file" $((level + 1)) "$file"
    done
}

# Start analysis from main entry points
ENTRY_POINTS=(
    "index.php"
    "login.html"
    "reservierungen.html"
    "reservation.html"
    "ReservationDetails.html"
    "statistiken.html" 
    "tisch-uebersicht.php"
    "zp/timeline-unified.html"
    "loading-test.html"
)

echo "🚀 Starting recursive analysis from entry points..."
echo ""

{
    echo "# WCI COMPLETE RECURSIVE DEPENDENCY ANALYSIS"
    echo "Generated: $(date)"
    echo "Max Depth: $MAX_DEPTH"
    echo ""
    
    for entry in "${ENTRY_POINTS[@]}"; do
        if [[ -f "$entry" ]]; then
            echo ""
            echo "=========================================="
            echo "🎯 ENTRY POINT: $entry"
            echo "=========================================="
            analyze_dependencies_recursive "$entry" 0 ""
            echo ""
        else
            echo "⚠️  Entry point not found: $entry"
        fi
    done
    
    echo ""
    echo "=========================================="
    echo "📊 ANALYSIS SUMMARY"
    echo "=========================================="
    echo "Total files analyzed: ${#ANALYZED_FILES[@]}"
    echo "Total dependencies mapped: ${#DEPENDENCY_GRAPH[@]}"
    echo ""
    
    echo "📄 ALL ANALYZED FILES:"
    printf '%s\n' "${ANALYZED_FILES[@]}" | sort | while read file; do
        if [[ -f "$file" ]]; then
            size=$(wc -c < "$file" 2>/dev/null)
            echo "  ✅ $file (${size} bytes)"
        else
            echo "  ❌ $file (missing)"
        fi
    done
    
    echo ""
    echo "🔗 DEPENDENCY GRAPH:"
    printf '%s\n' "${DEPENDENCY_GRAPH[@]}" | sort | uniq
    
    echo ""
    echo "🔍 ORPHAN DETECTION:"
    echo "Files not referenced by any entry point:"
    
    # Find all files in system
    all_files=($(find . -name "*.html" -o -name "*.php" -o -name "*.js" -o -name "*.css" | grep -v "/\." | sort))
    
    for file in "${all_files[@]}"; do
        file_clean="${file#./}"
        found=false
        for analyzed in "${ANALYZED_FILES[@]}"; do
            if [[ "$analyzed" == "$file_clean" ]]; then
                found=true
                break
            fi
        done
        
        if [[ "$found" == false ]]; then
            size=$(wc -c < "$file" 2>/dev/null)
            echo "  🏝️  $file_clean (${size} bytes) - POTENTIAL ORPHAN"
        fi
    done
    
} | tee "$OUTPUT_FILE"

echo ""
echo "✅ COMPLETE RECURSIVE ANALYSIS FINISHED!"
echo "📋 Full report saved to: $OUTPUT_FILE"
echo "⏱️  Analysis completed at: $(date)"
echo ""
echo "🔍 KEY FINDINGS:"
echo "  - Files analyzed: ${#ANALYZED_FILES[@]}"
echo "  - Dependencies found: ${#DEPENDENCY_GRAPH[@]}"
echo "  - Report saved to: $OUTPUT_FILE"
echo ""
echo "📚 Next steps:"
echo "  1. Review the complete dependency graph"
echo "  2. Check identified orphan files"
echo "  3. Update SYSTEM_DOCUMENTATION.md with findings"
