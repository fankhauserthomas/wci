#!/bin/bash
# WCI COMPLETE NETWORK DEPENDENCY ANALYSIS
# Datei-für-Datei Inhaltsanalyse zur korrekten Netzwerk-Erstellung

echo "🕸️ WCI COMPLETE NETWORK DEPENDENCY ANALYSIS"
echo "============================================"
echo "Start: $(date)"

OUTPUT_FILE="complete-network-analysis.txt"
TEMP_DIR="/tmp/wci_analysis"
rm -rf "$TEMP_DIR" 2>/dev/null
mkdir -p "$TEMP_DIR"

# SCHRITT 1: ALLE DATEIEN SAMMELN
echo "📂 Schritt 1: Alle Dateien sammeln..."
find . -name "*.html" -o -name "*.php" -o -name "*.js" -o -name "*.css" | grep -v "/\." | sort > "$TEMP_DIR/all_files.txt"

TOTAL_FILES=$(wc -l < "$TEMP_DIR/all_files.txt")
echo "   Gefunden: $TOTAL_FILES Dateien"

# SCHRITT 2: FÜR JEDE DATEI ALLE REFERENZEN EXTRAHIEREN
echo "🔍 Schritt 2: Datei-für-Datei Referenz-Extraktion..."

{
    echo "# WCI COMPLETE NETWORK ANALYSIS"
    echo "# Generated: $(date)"
    echo "# Total files: $TOTAL_FILES"
    echo ""
    
    counter=0
    while IFS= read -r file; do
        ((counter++))
        file_clean="${file#./}"
        
        echo "=================================================================="
        echo "FILE: $file_clean ($counter/$TOTAL_FILES)"
        echo "=================================================================="
        
        if [[ ! -f "$file" ]]; then
            echo "❌ FILE NOT FOUND"
            echo ""
            continue
        fi
        
        file_size=$(wc -c < "$file" 2>/dev/null || echo "0")
        file_lines=$(wc -l < "$file" 2>/dev/null || echo "0")
        echo "📊 Size: ${file_size}B, Lines: ${file_lines}"
        echo ""
        
        # ALLE MÖGLICHEN REFERENZEN EXTRAHIEREN
        echo "🔗 FOUND REFERENCES:"
        
        # HTML/CSS/JS INCLUDES
        if [[ "${file##*.}" == "html" ]]; then
            echo "  📄 CSS INCLUDES:"
            grep -n 'href.*\.css' "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                extracted=$(echo "$content" | grep -o 'href="[^"]*\.css"' | cut -d'"' -f2)
                if [[ -n "$extracted" ]]; then
                    if [[ -f "$extracted" ]]; then
                        echo "    ✅ Line $line_num: $extracted (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $extracted (MISSING)"
                    fi
                fi
            done
            
            echo "  📄 JS INCLUDES:"
            grep -n 'src.*\.js' "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                extracted=$(echo "$content" | grep -o 'src="[^"]*\.js"' | cut -d'"' -f2)
                if [[ -n "$extracted" ]]; then
                    if [[ -f "$extracted" ]]; then
                        echo "    ✅ Line $line_num: $extracted (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $extracted (MISSING)"
                    fi
                fi
            done
            
            echo "  📄 FORM ACTIONS:"
            grep -n 'action.*\.php' "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                extracted=$(echo "$content" | grep -o 'action="[^"]*\.php"' | cut -d'"' -f2)
                if [[ -n "$extracted" ]]; then
                    if [[ -f "$extracted" ]]; then
                        echo "    ✅ Line $line_num: $extracted (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $extracted (MISSING)"
                    fi
                fi
            done
            
            echo "  📄 HTML LINKS:"
            grep -n 'href.*\.\(html\|php\)' "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                extracted=$(echo "$content" | grep -o 'href="[^"]*\.\(html\|php\)"' | cut -d'"' -f2 | cut -d'?' -f1)
                if [[ -n "$extracted" ]] && [[ ! "$extracted" =~ ^https?: ]]; then
                    if [[ -f "$extracted" ]]; then
                        echo "    ✅ Line $line_num: $extracted (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $extracted (MISSING)"
                    fi
                fi
            done
        fi
        
        # JAVASCRIPT REFERENZEN
        if [[ "${file##*.}" == "js" ]]; then
            echo "  🟨 PHP API CALLS:"
            grep -n '[a-zA-Z0-9_-]*\.php' "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                # Alle .php Referenzen in dieser Zeile finden
                echo "$content" | grep -o '[a-zA-Z0-9_-]*\.php' | sort -u | while read -r php_file; do
                    if [[ -f "$php_file" ]]; then
                        echo "    ✅ Line $line_num: $php_file (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $php_file (MISSING)"
                    fi
                done
            done
            
            echo "  🟨 FETCH CALLS:"
            grep -n "fetch\s*(" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                echo "    📞 Line $line_num: $content"
            done
            
            echo "  🟨 WINDOW.LOCATION:"
            grep -n "window\.location" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                echo "    🔄 Line $line_num: $content"
            done
        fi
        
        # PHP INCLUDES/REQUIRES
        if [[ "${file##*.}" == "php" ]]; then
            echo "  🟦 PHP INCLUDES:"
            grep -n -E "(require_once|require|include_once|include)" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                # Versuche Dateinamen zu extrahieren
                extracted=$(echo "$content" | grep -o '"[^"]*\.\(php\|inc\)"' | cut -d'"' -f2)
                if [[ -n "$extracted" ]]; then
                    if [[ -f "$extracted" ]]; then
                        echo "    ✅ Line $line_num: $extracted (EXISTS)"
                    else
                        echo "    ❌ Line $line_num: $extracted (MISSING)"
                    fi
                else
                    echo "    📝 Line $line_num: $content"
                fi
            done
            
            echo "  🟦 PHP HEADER REDIRECTS:"
            grep -n "header.*Location" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                echo "    🔄 Line $line_num: $content"
            done
        fi
        
        # CSS IMPORTS
        if [[ "${file##*.}" == "css" ]]; then
            echo "  🎨 CSS IMPORTS:"
            grep -n "@import" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                echo "    📥 Line $line_num: $content"
            done
            
            echo "  🎨 URL() RESOURCES:"
            grep -n "url(" "$file" 2>/dev/null | while read -r line_info; do
                line_num=$(echo "$line_info" | cut -d: -f1)
                content=$(echo "$line_info" | cut -d: -f2-)
                echo "    🖼️  Line $line_num: $content"
            done
        fi
        
        # ALLE WEITEREN DATEI-REFERENZEN (GENERAL PATTERN)
        echo "  🔍 ALL FILE REFERENCES:"
        grep -n -E '\.(html|php|js|css|png|jpg|gif|svg|ico|woff|ttf)' "$file" 2>/dev/null | head -20 | while read -r line_info; do
            line_num=$(echo "$line_info" | cut -d: -f1)
            content=$(echo "$line_info" | cut -d: -f2-)
            echo "    📄 Line $line_num: $(echo "$content" | sed 's/^[[:space:]]*//')"
        done
        
        echo ""
        
        # Progress indicator
        if (( counter % 10 == 0 )); then
            echo "📊 Progress: $counter/$TOTAL_FILES files analyzed..."
        fi
        
    done < "$TEMP_DIR/all_files.txt"
    
    echo "=================================================================="
    echo "🏁 ANALYSIS COMPLETE"
    echo "=================================================================="
    echo "Total files analyzed: $TOTAL_FILES"
    echo "Completion time: $(date)"
    
} | tee "$OUTPUT_FILE"

echo ""
echo "✅ COMPLETE NETWORK ANALYSIS FINISHED!"
echo "📋 Complete report: $OUTPUT_FILE"
echo "📊 Total files: $TOTAL_FILES"
echo "🕸️ Now you have the complete network structure!"

# Cleanup
rm -rf "$TEMP_DIR"
