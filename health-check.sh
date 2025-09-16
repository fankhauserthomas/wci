#!/bin/bash
# WCI System Health Check
# Quick validation script to verify system integrity

echo "🏥 WCI SYSTEM HEALTH CHECK"
echo "=========================="
echo ""

ERRORS=0
WARNINGS=0

# Function to check file existence
check_file() {
    if [ -f "$1" ]; then
        echo "✅ $1"
    else
        echo "❌ $1 - MISSING"
        ((ERRORS++))
    fi
}

# Function to check optional file
check_optional() {
    if [ -f "$1" ]; then
        echo "✅ $1 (optional)"
    else
        echo "⚠️  $1 - Optional file missing"
        ((WARNINGS++))
    fi
}

echo "🚀 CRITICAL SYSTEM FILES:"
echo "-------------------------"
check_file "index.php"
check_file "login.html"
check_file "auth-simple.php"
check_file "config-simple.php"

echo ""
echo "📄 CORE PAGES:"
echo "--------------"
check_file "reservierungen.html"
check_file "reservation.html"
check_file "statistiken.html"
check_file "tisch-uebersicht.php"
check_file "tisch-uebersicht-resid.php"

echo ""
echo "🎨 ESSENTIAL CSS:"
echo "-----------------"
check_file "include/style.css"
check_file "reservation.css"
check_file "css/navigation.css"
check_file "css/navigation-integration.css"

echo ""
echo "⚙️ CORE JAVASCRIPT:"
echo "-------------------"
check_file "script.js"
check_file "reservation.js"
check_file "js/navigation.js"
check_file "barcode-scanner.js"

echo ""
echo "📡 API ENDPOINTS:"
echo "-----------------"
check_file "data.php"
check_file "getDashboardStats-simple.php"
check_file "getReservationDetails.php"
check_file "get-arrangements.php"

echo ""
echo "🔐 AUTHENTICATION:"
echo "------------------"
check_file "authenticate.php"
check_file "checkAuth.php"
check_file "logout.php"

echo ""
echo "🗄️ DATABASE CONFIG:"
echo "-------------------"
check_file "hp-db-config.php"

echo ""
echo "📚 LIBRARIES:"
echo "-------------"
check_file "libs/jquery.min.js"
check_optional "libs/qrcode.min.js"

echo ""
echo "🏗️ SPECIAL MODULES:"
echo "-------------------"
check_file "zp/timeline-unified.html"
check_file "zp/timeline-unified.js"
check_optional "zimmerplan.css"

echo ""
echo "🔍 LINK VALIDATION:"
echo "-------------------"

# Check internal links in index.php
if [ -f "index.php" ]; then
    echo "Checking links from index.php..."
    
    # Extract href links and check files
    grep -o 'href="[^"]*"' index.php | sed 's/href="//g' | sed 's/"//g' | while read link; do
        # Skip external links and anchors
        if [[ "$link" =~ ^http ]] || [[ "$link" =~ ^# ]] || [[ "$link" =~ ^\? ]]; then
            continue
        fi
        
        if [ -f "$link" ]; then
            echo "  ✅ $link"
        else
            echo "  ❌ $link - BROKEN LINK"
            ((ERRORS++))
        fi
    done
fi

echo ""
echo "📊 SUMMARY:"
echo "==========="
echo "❌ Critical Errors: $ERRORS"
echo "⚠️  Warnings: $WARNINGS"

if [ $ERRORS -eq 0 ]; then
    echo "🎉 System appears healthy! Core files are present."
    echo ""
    echo "🔧 RECOMMENDED ACTIONS:"
    echo "- Run system optimization: ./optimize-system.sh"
    echo "- Test core functionality manually"
    echo "- Review SYSTEM_DOCUMENTATION.md"
    exit 0
else
    echo "🚨 CRITICAL ISSUES DETECTED!"
    echo "- $ERRORS critical files missing"
    echo "- DO NOT optimize until issues are resolved"
    echo "- Check file paths and restore missing files"
    exit 1
fi
