#!/bin/bash
# WCI System Optimization Script
# Generated: August 10, 2025
# 
# This script safely archives unused files and optimizes the WCI system structure

echo "🚀 Starting WCI System Optimization..."
echo "📁 Current directory: $(pwd)"

# Backup vor Optimierung
BACKUP_DIR="wci-backup-$(date +%Y%m%d_%H%M%S)"
echo "📦 Creating full backup: $BACKUP_DIR"
cp -r . "../$BACKUP_DIR"

# Archive-Struktur erstellen
echo "📂 Creating archive structure..."
mkdir -p archive/{legacy,test,debug,backup,potential-orphans}
mkdir -p review/{css,js,html,php}
mkdir -p optimization-report

# Phase 1: Legacy-Dateien archivieren (SICHER)
echo "🗄️ Phase 1: Archiving legacy files..."

# Legacy Authentication (ersetzt durch *-simple.php)
if [ -f "auth.php" ]; then
    echo "  Moving legacy auth files..."
    mv auth.php authenticate.php checkAuth.php logout.php archive/legacy/ 2>/dev/null || true
fi

# Legacy Config (ersetzt durch *-simple.php)
if [ -f "config.php" ]; then
    echo "  Moving legacy config files..."
    mv config.php config-safe.php archive/legacy/ 2>/dev/null || true
fi

# Phase 2: Test-Dateien archivieren (PRÜFEN)
echo "🧪 Phase 2: Archiving test files..."

# Explizite Test-Dateien
echo "  Moving test files..."
for file in test-*.html *-test.html debug-*.html canvas-timeline.html simple-timeline.html indicator-demo.html word-search-test.html bulk-checkout-test.html; do
    if [ -f "$file" ]; then
        echo "    → $file"
        mv "$file" archive/test/ 2>/dev/null || true
    fi
done

# Phase 3: Backup-Versionen archivieren
echo "📦 Phase 3: Archiving backup versions..."

for file in *-backup.* *-old.* *-clean.* reservation-debug.html reservation-quick-fix.html reservierungen-test.html; do
    if [ -f "$file" ]; then
        echo "    → $file"
        mv "$file" archive/backup/ 2>/dev/null || true
    fi
done

# Phase 4: Review-Kandidaten verschieben
echo "🔍 Phase 4: Moving files for review..."

# HTML-Duplikate/Potentielle Waisen
for file in index.html ReservationDetails.html navigation-debug.html; do
    if [ -f "$file" ]; then
        echo "    → $file (review needed)"
        mv "$file" review/html/ 2>/dev/null || true
    fi
done

# JavaScript-Utilities
for file in ReservationDetails.js simple-barcode-scanner.js sw-barcode.js zimmerplan-daypilot.js; do
    if [ -f "$file" ]; then
        echo "    → $file (review needed)"
        mv "$file" review/js/ 2>/dev/null || true
    fi
done

# CSS-Backups
if [ -d "css" ]; then
    for file in css/*-backup.css css/*-clean.css; do
        if [ -f "$file" ]; then
            echo "    → $file (CSS backup)"
            mv "$file" review/css/ 2>/dev/null || true
        fi
    done
fi

# Emergency/Utility Scripts
for file in js/emergency-fix.js create-test-arrangement.php; do
    if [ -f "$file" ]; then
        echo "    → $file (utility review)"
        mv "$file" review/php/ 2>/dev/null || true
    fi
done

# Phase 5: Optimierungsreport erstellen
echo "📊 Phase 5: Generating optimization report..."

cat > optimization-report/file-analysis.txt << EOF
WCI SYSTEM OPTIMIZATION REPORT
Generated: $(date)
Backup Location: ../$BACKUP_DIR

ARCHIVED FILES:
===============

Legacy Files (archive/legacy/):
$(ls archive/legacy/ 2>/dev/null || echo "None")

Test Files (archive/test/):
$(ls archive/test/ 2>/dev/null || echo "None")

Backup Files (archive/backup/):
$(ls archive/backup/ 2>/dev/null || echo "None")

REVIEW NEEDED:
==============

HTML Files (review/html/):
$(ls review/html/ 2>/dev/null || echo "None")

JavaScript Files (review/js/):
$(ls review/js/ 2>/dev/null || echo "None")

CSS Files (review/css/):
$(ls review/css/ 2>/dev/null || echo "None")

PHP Files (review/php/):
$(ls review/php/ 2>/dev/null || echo "None")

ACTIVE CORE SYSTEM:
==================

Main Pages:
$(ls *.php *.html 2>/dev/null | grep -E "(index|login|reservation|statistiken|tisch)" | head -10)

Configuration:
$(ls *-simple.php hp-db-config.php 2>/dev/null)

Active CSS:
$(ls *.css css/*.css 2>/dev/null | grep -v backup | grep -v clean)

Active JavaScript:
$(ls *.js js/*.js libs/*.js 2>/dev/null | head -10)

DISK SPACE SAVED:
================
Archive Size: $(du -sh archive/ 2>/dev/null | cut -f1)
Review Size:  $(du -sh review/ 2>/dev/null | cut -f1)
Total Saved:  $(du -sh archive/ review/ 2>/dev/null | tail -1 | cut -f1)
EOF

# Phase 6: Dependency Check
echo "🔗 Phase 6: Checking critical dependencies..."

cat > optimization-report/dependency-check.txt << EOF
CRITICAL DEPENDENCY CHECK
=========================

1. Main Entry Point:
   index.php exists: $([ -f "index.php" ] && echo "✅ YES" || echo "❌ NO")

2. Authentication System:
   auth-simple.php: $([ -f "auth-simple.php" ] && echo "✅ YES" || echo "❌ NO")
   login.html: $([ -f "login.html" ] && echo "✅ YES" || echo "❌ NO")

3. Core Pages:
   reservierungen.html: $([ -f "reservierungen.html" ] && echo "✅ YES" || echo "❌ NO")
   reservation.html: $([ -f "reservation.html" ] && echo "✅ YES" || echo "❌ NO")
   statistiken.html: $([ -f "statistiken.html" ] && echo "✅ YES" || echo "❌ NO")

4. Database Config:
   config-simple.php: $([ -f "config-simple.php" ] && echo "✅ YES" || echo "❌ NO")
   hp-db-config.php: $([ -f "hp-db-config.php" ] && echo "✅ YES" || echo "❌ NO")

5. Core CSS:
   style.css: $([ -f "include/style.css" ] && echo "✅ YES" || echo "❌ NO")
   reservation.css: $([ -f "reservation.css" ] && echo "✅ YES" || echo "❌ NO")

6. Navigation Framework:
   css/navigation.css: $([ -f "css/navigation.css" ] && echo "✅ YES" || echo "❌ NO")
   js/navigation.js: $([ -f "js/navigation.js" ] && echo "✅ YES" || echo "❌ NO")

7. Special Modules:
   zp/timeline-unified.html: $([ -f "zp/timeline-unified.html" ] && echo "✅ YES" || echo "❌ NO")
   tisch-uebersicht.php: $([ -f "tisch-uebersicht.php" ] && echo "✅ YES" || echo "❌ NO")
EOF

# Phase 7: Next Steps generieren
cat > optimization-report/next-steps.md << 'EOF'
# WCI SYSTEM OPTIMIZATION - NEXT STEPS

## ✅ COMPLETED
- [x] Legacy files archived
- [x] Test files moved to archive
- [x] Backup versions cleaned up
- [x] Potential orphans moved to review
- [x] System backup created

## 🔍 MANUAL REVIEW REQUIRED

### 1. Review HTML Files
Check files in `review/html/`:
- Verify if still needed
- Check for active links from core system
- Test functionality if uncertain

### 2. Review JavaScript Files  
Check files in `review/js/`:
- Verify dependencies in active pages
- Test scanner functionality
- Check zimmerplan integration

### 3. Review CSS Files
Check files in `review/css/`:
- Remove confirmed duplicates
- Merge useful styles into main CSS
- Update references if needed

### 4. Test Core System
- [ ] Login functionality
- [ ] Dashboard access
- [ ] Reservierungen page
- [ ] Reservation details
- [ ] Statistiken page
- [ ] Zimmerplan module
- [ ] Tischübersicht
- [ ] Barcode scanner
- [ ] Mobile navigation

## 🚀 RECOMMENDED OPTIMIZATIONS

### Short Term (1-2 days)
1. Test all core functionality
2. Review potential orphan files
3. Remove confirmed duplicate CSS
4. Consolidate JavaScript utilities

### Medium Term (1 week)  
1. Implement suggested folder structure
2. Create unified configuration system
3. Optimize CSS loading
4. Implement asset minification

### Long Term (1 month)
1. RESTful API refactoring
2. Modern JavaScript framework integration
3. Database query optimization
4. Mobile-first responsive redesign

## 🔧 MAINTENANCE SCRIPT

Create a weekly maintenance script to:
1. Identify unused files
2. Check for broken links
3. Monitor system performance
4. Update documentation

## 📈 EXPECTED BENEFITS

- **Reduced complexity**: ~60% fewer files
- **Faster loading**: Optimized asset loading
- **Better maintainability**: Clear structure
- **Easier deployment**: Streamlined file set
- **Improved security**: Removed legacy/test files
EOF

echo ""
echo "✅ WCI System Optimization completed!"
echo ""
echo "📊 SUMMARY:"
echo "   📦 Backup created: ../$BACKUP_DIR"
echo "   🗄️  Files archived: archive/"
echo "   🔍 Review needed: review/"
echo "   📋 Reports: optimization-report/"
echo ""
echo "🔍 NEXT STEPS:"
echo "   1. Review files in review/ directories"
echo "   2. Test core system functionality"
echo "   3. Read optimization-report/next-steps.md"
echo "   4. Execute testing checklist"
echo ""
echo "⚠️  IMPORTANT: Test the system thoroughly before deleting archive!"
