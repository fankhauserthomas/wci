# WCI SYSTEM DOCUMENTATION
# ======================
# Generated: 2025-08-10 from complete network analysis
# Purpose: Machine-readable documentation for AI-assisted development
# Format: Structured markdown with metadata for automated processing

## SYSTEM OVERVIEW
- **Project**: WebCheckin (WCI) - Hotel Reservation Management System  
- **Technology Stack**: PHP 8.1+, MySQL/HP Database, HTML5, CSS3, JavaScript ES6+
- **Total Files**: 247 files analyzed
- **Architecture**: Multi-database system with sync capabilities
- **Primary Function**: Hotel reservation management with HP integration

## DATABASE ARCHITECTURE

### PRIMARY DATABASES
```yaml
databases:
  main:
    type: "MySQL"
    config_file: "config.php"
    description: "Main application database"
    tables:
      - "AV_Res" # Main reservations
      - "AV_ResNames" # Guest names  
      - "AV_Cap" # Capacity/arrangements
      - "rooms" # Room management
      - "origins" # Guest origins
      - "countries" # Country data
      - "diets" # Dietary requirements
    
  hp_system:
    type: "HP Database"
    config_file: "hp-db-config.php"
    description: "HP hotel system integration"
    tables:
      - "arrangements" # HP arrangements
      - "guest_data" # HP guest information
    connection_method: "Direct HP database connection"
    
  sync_system:
    type: "Bidirectional Sync"
    manager: "SyncManager.php"
    description: "Synchronization between Main and HP databases"
    trigger_files:
      - "syncTrigger.php"
      - "create_extended_triggers.php"
      - "create_fallback_triggers.php"
```

### DATABASE USAGE MAPPING
```yaml
config_files:
  - file: "config.php"
    databases: ["main"]
    used_by:
      - "addReservation.php"
      - "getReservationDetails.php" 
      - "updateReservationNames.php"
      - "toggleGuideFlag.php"
      - "toggleNoShow.php"
      - "toggleStorno.php"
      - "deleteReservation.php"
      - "printSelected.php"
      - "getDiets.php"
      - "getArrangements.php"
      - "getCountries.php"
      - "getOrigins.php"
      - "getReservationDetailsFull.php"
      - "updateReservationDetails.php"
      - "searchBarcode.php"
      - "getBookingUrl.php"
      
  - file: "hp-db-config.php"
    databases: ["hp_system"]
    used_by:
      - "tisch-uebersicht.php"
      - "tisch-uebersicht-resid.php"
      - "get-hp-arrangements.php"
      - "get-hp-arrangements-header.php"
      - "get-hp-arrangements-table.php"
      - "save-hp-arrangements.php" 
      - "save-hp-arrangements-table.php"
      
  - file: "SyncManager.php"
    databases: ["main", "hp_system"]
    used_by:
      - "syncTrigger.php"
      - "sync-database.php"
      - "extend_sync_system.php"
      - Multiple test files
```

## CORE SYSTEM ARCHITECTURE

### ENTRY POINTS
```yaml
entry_points:
  primary:
    - file: "index.php"
      type: "dashboard"
      size: "19030B"
      dependencies: []
      database: "main"
      
    - file: "login.html" 
      type: "authentication"
      size: "9968B"
      dependencies:
        css: ["style.css"]
      database: "none"
      
    - file: "reservation.html"
      type: "reservation_management" 
      size: "27202B"
      dependencies:
        css: ["style.css", "reservation.css", "css/navigation.css", "css/navigation-integration.css"]
        js: ["js/navigation.js", "js/email-utils.js", "js/http-utils.js", "js/loading-overlay.js", "auto-barcode-scanner.js", "reservation.js"]
      database: "main"
      
    - file: "ReservationDetails.html"
      type: "reservation_details"
      size: "34136B" 
      dependencies:
        css: ["style.css", "reservation.css", "css/navigation.css", "css/navigation-integration.css"]
        js: ["js/http-utils.js", "js/loading-overlay.js", "ReservationDetails.js", "js/navigation.js"]
      database: "main"
      
    - file: "reservierungen.html"
      type: "reservation_list"
      size: "8726B"
      dependencies:
        css: ["style.css", "css/navigation.css", "css/navigation-integration.css"]
        js: ["js/email-utils.js", "js/http-utils.js", "js/loading-overlay.js", "js/sync-utils.js", "auto-barcode-scanner.js", "script.js", "js/navigation.js"]
      database: "main"
      
    - file: "statistiken.html"
      type: "statistics"
      size: "34392B"
      dependencies:
        css: ["style.css", "css/navigation.css", "css/navigation-integration.css"] 
        js: ["js/http-utils.js", "js/loading-overlay.js", "js/navigation.js"]
      database: "main"

  hp_integration:
    - file: "tisch-uebersicht.php"
      type: "hp_table_overview"
      size: "87338B"
      dependencies:
        php: ["auth-simple.php", "hp-db-config.php"]
        redirects: ["login.html"]
        apis: ["save-arrangement-inline.php", "get-arrangement-cells-data.php", "get-arrangements.php", "get-guest-arrangements.php", "save-arrangements.php", "sync-database.php"]
      database: "hp_system"
      
    - file: "tisch-uebersicht-resid.php"
      type: "hp_table_filtered"
      size: "64326B" 
      dependencies:
        php: ["hp-db-config.php"]
        apis: ["save-arrangement-inline.php", "get-arrangement-cells-data.php"]
      database: "hp_system"

  secondary:
    - file: "GastDetail.html"
      type: "guest_details"
      size: "32866B"
      database: "main"
      
    - file: "transport.html"  
      type: "transport_management"
      size: "22495B"
      database: "main"
      
    - file: "navigation-demo.html"
      type: "navigation_demo"
      size: "14874B"
      database: "none"
```

### JAVASCRIPT MODULES
```yaml
js_modules:
  core:
    - file: "reservation.js"
      size: "121617B"
      type: "main_reservation_logic"
      api_dependencies: 22
      apis:
        - "addReservationNames.php"
        - "deleteReservationNames.php"
        - "deleteReservation.php"
        - "getArrangements.php"
        - "GetCardPrinters.php"
        - "getDiets.php"
        - "get-hp-arrangements.php"
        - "get-hp-arrangements-table.php"
        - "getReservationDetails.php"
        - "getReservationNames.php"
        - "printSelected.php"
        - "save-hp-arrangements.php"
        - "save-hp-arrangements-table.php"
        - "tisch-uebersicht-resid.php"
        - "toggleGuideFlag.php"
        - "toggleNoShow.php"
        - "toggleStorno.php"
        - "updateReservationNamesArrangement.php"
        - "updateReservationNamesCheckin.php"
        - "updateReservationNamesCheckout.php"
        - "updateReservationNamesDiet.php"
        - "updateReservationNames.php"
        
    - file: "script.js"
      size: "34886B"
      type: "reservation_list_logic"
      api_dependencies: 6
      apis:
        - "addReservation.php"
        - "data.php"
        - "getArrangements.php"
        - "getBookingUrl.php"
        - "getOrigins.php"
        - "getReservationDetails.php"
        
    - file: "ReservationDetails.js"
      size: "20903B"
      type: "reservation_detail_logic"
      api_dependencies: 5
      apis:
        - "getArrangements.php"
        - "getCountries.php"
        - "getOrigins.php"
        - "getReservationDetailsFull.php"
        - "updateReservationDetails.php"

  utilities:
    - file: "js/http-utils.js"
      size: "22496B"
      type: "http_utilities"
      apis: ["ping.php"]
      
    - file: "js/loading-overlay.js"
      size: "19233B"
      type: "loading_ui"
      
    - file: "js/navigation.js"
      size: "18697B"
      type: "navigation_system"
      apis: ["index.php"]
      
    - file: "js/sync-utils.js"
      size: "6963B"
      type: "sync_utilities"
      apis: ["syncTrigger.php"]
      
    - file: "js/email-utils.js"
      size: "4869B"
      type: "email_utilities"
      apis: ["getBookingUrl.php"]

  specialized:
    - file: "auto-barcode-scanner.js"
      size: "8684B"
      type: "barcode_scanner"
      apis: ["searchBarcode.php"]
      
    - file: "zp/timeline-unified.js"
      size: "175326B"
      type: "zimmerplan_timeline"
      
    - file: "zp/timeline-config.js"
      size: "18139B"
      type: "timeline_configuration"
      
    - file: "libs/jquery.min.js"
      size: "93638B"
      type: "external_library"
      
    - file: "libs/qrcode.js"
      size: "33782B"
      type: "qr_generation"
```

### CSS ARCHITECTURE
```yaml
css_modules:
  base:
    - file: "style.css"
      size: "13867B"
      type: "base_styles"
      used_by:
        - "login.html"
        - "reservation.html"
        - "ReservationDetails.html"
        - "reservierungen.html"
        - "statistiken.html"
        - "GastDetail.html"
        - "navigation-demo.html"
        - "index.html"
        
    - file: "reservation.css"
      size: "32176B"
      type: "reservation_styles"
      used_by:
        - "reservation.html"
        - "ReservationDetails.html"
        - "GastDetail.html"

  navigation:
    - file: "css/navigation.css"
      size: "9416B"
      type: "navigation_styles"
      used_by:
        - "reservation.html"
        - "ReservationDetails.html"
        - "reservierungen.html"
        - "statistiken.html"
        - "GastDetail.html"
        - "navigation-demo.html"
        - "index.html"
        
    - file: "css/navigation-integration.css"
      size: "2235B"
      type: "navigation_integration"
      used_by:
        - "reservation.html"
        - "ReservationDetails.html"
        - "reservierungen.html"
        - "statistiken.html"
        - "GastDetail.html"
        - "navigation-demo.html"
        - "index.html"

  specialized:
    - file: "zimmerplan.css"
      size: "11328B"
      type: "zimmerplan_styles"
      status: "orphaned"
```

## API ENDPOINTS

### MAIN DATABASE APIS
```yaml
reservation_management:
  - file: "addReservation.php"
    size: "3981B"
    database: "main"
    config: "config.php"
    
  - file: "getReservationDetails.php"
    size: "3048B"
    database: "main"
    config: "config.php"
    
  - file: "getReservationDetailsFull.php"
    size: "2646B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationDetails.php"
    size: "5447B"
    database: "main"
    config: "config.php"
    
  - file: "deleteReservation.php"
    size: "2734B"
    database: "main"
    config: "config.php"

guest_management:
  - file: "addReservationNames.php"
    size: "1150B"
    database: "main"
    config: "config.php"
    
  - file: "getReservationNames.php"
    size: "3763B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationNames.php"
    size: "1450B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationNamesCheckin.php"
    size: "1502B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationNamesCheckout.php"
    size: "1499B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationNamesArrangement.php"
    size: "1087B"
    database: "main"
    config: "config.php"
    
  - file: "updateReservationNamesDiet.php"
    size: "908B"
    database: "main"
    config: "config.php"
    
  - file: "deleteReservationNames.php"
    size: "981B"
    database: "main"
    config: "config.php"

status_management:
  - file: "toggleGuideFlag.php"
    size: "1059B"
    database: "main"
    config: "config.php"
    
  - file: "toggleNoShow.php"
    size: "1258B"
    database: "main"
    config: "config.php"
    
  - file: "toggleStorno.php"
    size: "4826B"
    database: "main"
    config: "config.php"

data_retrieval:
  - file: "getArrangements.php"
    size: "541B"
    database: "main"
    config: "config.php"
    
  - file: "getDiets.php"
    size: "418B"
    database: "main"
    config: "config.php"
    
  - file: "getCountries.php"
    size: "450B"
    database: "main"
    config: "config.php"
    
  - file: "getOrigins.php"
    size: "715B"
    database: "main"
    config: "config.php"
    
  - file: "data.php"
    size: "3253B"
    database: "main"
    config: "config.php"

utilities:
  - file: "searchBarcode.php"
    size: "3165B"
    database: "main"
    config: "config.php"
    
  - file: "getBookingUrl.php"
    size: "724B"
    database: "main"
    config: "config.php"
    
  - file: "printSelected.php"
    size: "5022B"
    database: "main"
    config: "config.php"
    
  - file: "GetCardPrinters.php"
    size: "534B"
    database: "main"
    config: "config.php"
    
  - file: "ping.php"
    size: "883B"
    database: "none"
```

### HP DATABASE APIS
```yaml
hp_arrangements:
  - file: "get-hp-arrangements.php"
    size: "6615B"
    database: "hp_system"
    config: "hp-db-config.php"
    auth: "auth-simple.php"
    
  - file: "get-hp-arrangements-header.php"
    size: "7321B"
    database: "hp_system"
    config: "hp-db-config.php"
    
  - file: "get-hp-arrangements-table.php"
    size: "2713B"
    database: "hp_system"
    config: "hp-db-config.php"
    auth: "auth-simple.php"
    
  - file: "save-hp-arrangements.php"
    size: "6338B"
    database: "hp_system"
    config: "hp-db-config.php"
    auth: "auth-simple.php"
    
  - file: "save-hp-arrangements-table.php"
    size: "3582B"
    database: "hp_system"
    config: "hp-db-config.php"
    auth: "auth-simple.php"

hp_utilities:
  - file: "get-arrangement-cells-data.php"
    size: "1739B"
    database: "hp_system"
    
  - file: "get-arrangements.php"
    size: "943B"
    database: "hp_system"
    
  - file: "save-arrangement-inline.php"
    size: "6900B"
    database: "hp_system"
    
  - file: "save-arrangements.php"
    size: "8310B"
    database: "hp_system"
    
  - file: "get-guest-arrangements.php"
    size: "1531B"
    database: "hp_system"
```

### SYNC SYSTEM APIS
```yaml
sync_management:
  - file: "syncTrigger.php"
    size: "1704B"
    databases: ["main", "hp_system"]
    config: "config.php"
    manager: "SyncManager.php"
    
  - file: "sync-database.php"
    size: "11869B"
    databases: ["main", "hp_system"]
    manager: "SyncManager.php"
    
  - file: "SyncManager.php"
    size: "33663B"
    type: "sync_manager_class"
    databases: ["main", "hp_system"]
```

## AUTHENTICATION SYSTEM
```yaml
auth_files:
  simple:
    - file: "auth-simple.php"
      size: "1676B"
      type: "simple_authentication"
      used_by:
        - "tisch-uebersicht.php"
        - "get-hp-arrangements.php"
        - "get-hp-arrangements-table.php"
        - "save-hp-arrangements.php"
        - "save-hp-arrangements-table.php"
        
  full:
    - file: "auth.php"
      size: "3957B"
      type: "full_authentication"
      status: "potentially_unused"
      
  utilities:
    - file: "authenticate.php"
      size: "1834B"
      type: "authentication_handler"
      status: "potentially_unused"
      
    - file: "authenticate-simple.php"
      size: "1908B"
      type: "simple_auth_handler"
      status: "potentially_unused"
      
    - file: "checkAuth.php"
      size: "810B"
      type: "auth_checker"
      status: "potentially_unused"
      
    - file: "checkAuth-simple.php"
      size: "898B"
      type: "simple_auth_checker"
      status: "potentially_unused"
      
    - file: "logout.php"
      size: "517B"
      type: "logout_handler"
      status: "potentially_unused"
      
    - file: "logout-simple.php"
      size: "649B"
      type: "simple_logout_handler"
      status: "potentially_unused"
```

## FILE CLASSIFICATION

### ACTIVE CORE FILES (58 files)
```yaml
html_entry_points: 12
  - "index.php"
  - "login.html"
  - "reservation.html"
  - "ReservationDetails.html"
  - "reservierungen.html"
  - "statistiken.html"
  - "tisch-uebersicht.php"
  - "tisch-uebersicht-resid.php"
  - "GastDetail.html"
  - "transport.html"
  - "navigation-demo.html"
  - "index.html"

css_active: 5
  - "style.css"
  - "reservation.css"
  - "css/navigation.css"
  - "css/navigation-integration.css"
  - "zimmerplan.css"

js_active: 18
  - "reservation.js"
  - "script.js"
  - "ReservationDetails.js"
  - "js/http-utils.js"
  - "js/loading-overlay.js"
  - "js/navigation.js"
  - "js/sync-utils.js"
  - "js/email-utils.js"
  - "auto-barcode-scanner.js"
  - "zp/timeline-unified.js"
  - "zp/timeline-config.js"
  - "libs/jquery.min.js"
  - "libs/qrcode.js"
  - "libs/qrcode.min.js"
  - "barcode-scanner.js"
  - "simple-barcode-scanner.js"
  - "js/emergency-fix.js"
  - "js/auth-protection.js"

php_apis: 23
  - Main database APIs (19 files)
  - HP database APIs (4 files)
  - Authentication (1 active file: auth-simple.php)
  - Configuration (3 files: config.php, hp-db-config.php, SyncManager.php)
```

### ARCHIVE CANDIDATES (189 files)
```yaml
categories:
  test_files: 45
    pattern: "test*.php", "test*.html"
    location: "./tests/", root level
    action: "move_to_archive/testing"
    
  debug_files: 12
    pattern: "debug*.php", "*-debug.php"
    location: "./debug/", root level  
    action: "move_to_archive/debugging"
    
  backup_files: 8
    pattern: "*backup*", "*-old.php"
    location: "./backups/", root level
    action: "move_to_archive/backups"
    
  empty_files: 31
    size: "0B"
    action: "safe_delete"
    
  hr_system_files: 3
    location: "./hrs/"
    status: "potentially_active"
    action: "evaluate_for_archive"
    
  migration_files: 15
    pattern: "*migration*", "*sync*", "*trigger*"
    status: "potentially_active"
    action: "evaluate_for_archive"
    
  duplicate_auth_files: 7
    files: ["auth.php", "authenticate*.php", "checkAuth*.php", "logout*.php"]
    keep: "auth-simple.php"
    action: "archive_unused"
    
  unused_config_files: 3
    files: ["config-safe.php", "config-simple.php", "tests/config-simple.php"]
    keep: "config.php", "hp-db-config.php"
    action: "archive_unused"
```

## OPTIMIZATION RECOMMENDATIONS

### IMMEDIATE ACTIONS
```yaml
safe_cleanup:
  - delete_empty_files: 31
  - archive_test_files: 45
  - archive_debug_files: 12
  - archive_backup_files: 8
  - consolidate_auth_system: 7
  
performance_optimization:
  - minify_large_js: ["reservation.js", "zp/timeline-unified.js"]
  - optimize_css: ["reservation.css", "style.css"]
  - compress_images: ["pic/*.svg", "pic/*.png"]
  
security_review:
  - audit_auth_system: "Multiple auth implementations"
  - review_hp_access: "Direct database access"
  - validate_api_endpoints: "Input sanitization"
```

### ARCHITECTURE IMPROVEMENTS
```yaml
suggested_refactoring:
  - consolidate_auth: "Use single auth system"
  - api_standardization: "Consistent API response format"
  - database_abstraction: "Abstract database access layer"
  - error_handling: "Centralized error management"
  - logging_system: "Structured logging implementation"
```

## MAINTENANCE NOTES

### CRITICAL DEPENDENCIES
```yaml
database_configs:
  - "config.php" # Required for main system
  - "hp-db-config.php" # Required for HP integration
  
core_logic:
  - "reservation.js" # Main reservation functionality
  - "SyncManager.php" # Database synchronization
  - "tisch-uebersicht-resid.php" # HP table management (CUSTOM OPTIMIZED)
  
authentication:
  - "auth-simple.php" # Currently active auth system
```

### VERSION CONTROL NOTES
```yaml
custom_modifications:
  - file: "tisch-uebersicht-resid.php"
    modification: "LEFT JOIN optimization for entries without table assignments"
    date: "2025-08-10"
    description: "Added COALESCE for 'Kein Tisch' display and improved query performance"
    
recent_analysis:
  - date: "2025-08-10"
  - method: "Complete network dependency analysis"
  - files_analyzed: 247
  - documentation_created: "Full system mapping for AI development"
```

---
*This documentation is optimized for machine reading and AI-assisted development. All file paths, dependencies, and database connections are explicitly mapped for automated processing.*
