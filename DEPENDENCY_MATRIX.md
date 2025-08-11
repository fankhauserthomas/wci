# WCI SYSTEM DEPENDENCY MATRIX
# Machine-readable system analysis for automated tooling

## FILE_DEPENDENCIES

### PRIMARY_ENTRY_POINTS
index.php:
  requires: [auth-simple.php]
  includes: [style.css]
  links_to: [reservierungen.html, statistiken.html, zp/timeline-unified.html, loading-test.html, tisch-uebersicht.php, login.html]
  status: ACTIVE_PRIMARY

login.html:
  submits_to: [authenticate-simple.php]
  redirects_to: [index.php]
  status: ACTIVE_AUTH

### CORE_PAGES
reservierungen.html:
  includes: [reservation.css, css/navigation.css, css/navigation-integration.css, script.js, js/navigation.js]
  api_calls: [data.php, getDashboardStats-simple.php]
  links_to: [reservation.html]
  status: ACTIVE_CORE

reservation.html:
  includes: [reservation.css, css/navigation.css, css/navigation-integration.css, reservation.js, js/navigation.js]
  api_calls: [getReservationDetails.php, get-arrangements.php]
  modals: [tisch-uebersicht-resid.php]
  status: ACTIVE_CORE

statistiken.html:
  includes: [style.css]
  api_calls: [getDashboardStats-simple.php]
  status: ACTIVE_CORE

tisch-uebersicht.php:
  requires: [hp-db-config.php, config-simple.php]
  links_to: [tisch-uebersicht-resid.php]
  status: ACTIVE_CORE

tisch-uebersicht-resid.php:
  requires: [hp-db-config.php]
  api_calls: [save-arrangement-inline.php]
  status: ACTIVE_CORE

### AUTHENTICATION_SYSTEM
auth-simple.php:
  config: [config-simple.php]
  status: ACTIVE_AUTH

authenticate-simple.php:
  requires: [auth-simple.php]
  status: ACTIVE_AUTH

checkAuth-simple.php:
  requires: [auth-simple.php]
  status: ACTIVE_AUTH

logout-simple.php:
  requires: [auth-simple.php]
  status: ACTIVE_AUTH

### DATABASE_LAYER
config-simple.php:
  status: ACTIVE_CONFIG

hp-db-config.php:
  status: ACTIVE_CONFIG

### API_ENDPOINTS
data.php:
  requires: [config-simple.php, checkAuth-simple.php]
  status: ACTIVE_API

getDashboardStats-simple.php:
  requires: [config-simple.php, checkAuth-simple.php]
  status: ACTIVE_API

getReservationDetails.php:
  requires: [config-simple.php, checkAuth-simple.php]
  status: ACTIVE_API

get-arrangements.php:
  requires: [hp-db-config.php, checkAuth-simple.php]
  status: ACTIVE_API

save-arrangement-inline.php:
  requires: [hp-db-config.php]
  status: ACTIVE_API

### SPECIAL_MODULES
zp/timeline-unified.html:
  includes: [zp/timeline-unified.js, zp/timeline-config.js, zimmerplan.css]
  api_calls: [getZimmerplanData.php]
  status: ACTIVE_MODULE

### STYLING_FRAMEWORK
style.css:
  used_by: [index.php, statistiken.html]
  status: ACTIVE_CSS

reservation.css:
  used_by: [reservierungen.html, reservation.html]
  status: ACTIVE_CSS

css/navigation.css:
  used_by: [reservierungen.html, reservation.html]
  status: ACTIVE_CSS

css/navigation-integration.css:
  used_by: [reservierungen.html, reservation.html]
  status: ACTIVE_CSS

zimmerplan.css:
  used_by: [zp/timeline-unified.html]
  status: ACTIVE_CSS

### JAVASCRIPT_FRAMEWORK
script.js:
  used_by: [reservierungen.html]
  status: ACTIVE_JS

reservation.js:
  used_by: [reservation.html]
  status: ACTIVE_JS

js/navigation.js:
  used_by: [reservierungen.html, reservation.html]
  status: ACTIVE_JS

zp/timeline-unified.js:
  used_by: [zp/timeline-unified.html]
  status: ACTIVE_JS

barcode-scanner.js:
  used_by: [various pages via dynamic loading]
  status: ACTIVE_JS

### LIBRARIES
libs/jquery.min.js:
  used_by: [multiple pages]
  status: ACTIVE_LIB

libs/qrcode.min.js:
  used_by: [reservation features]
  status: ACTIVE_LIB

## ORPHAN_ANALYSIS

### LEGACY_FILES (Safe to archive)
auth.php: REPLACED_BY auth-simple.php
authenticate.php: REPLACED_BY authenticate-simple.php  
checkAuth.php: REPLACED_BY checkAuth-simple.php
logout.php: REPLACED_BY logout-simple.php
config.php: REPLACED_BY config-simple.php
config-safe.php: BACKUP_VERSION

### TEST_FILES (Archive candidates)
test-*.html: DEVELOPMENT_TESTING
debug-*.html: DEBUG_TOOLS
*-test.html: TESTING_VERSIONS
loading-test.html: LINKED_FROM index.php AS "Tools" (Review needed)

### BACKUP_FILES (Archive candidates)  
reservation-backup.html: BACKUP_VERSION
reservation-clean.html: BACKUP_VERSION
reservation-debug.html: DEBUG_VERSION
reservation-quick-fix.html: PATCH_VERSION
reservierungen-test.html: TEST_VERSION

### REVIEW_CANDIDATES (Manual inspection needed)
index.html: POTENTIAL_DUPLICATE_OF index.php
ReservationDetails.html: POTENTIAL_ORPHAN
ReservationDetails.js: POTENTIAL_ORPHAN
simple-barcode-scanner.js: POTENTIAL_ALTERNATIVE
sw-barcode.js: SERVICE_WORKER (Check usage)
zimmerplan-daypilot.js: ALTERNATIVE_IMPLEMENTATION

### CSS_DUPLICATES (Review needed)
css/navigation-integration-backup.css: BACKUP_VERSION
css/navigation-integration-clean.css: ALTERNATIVE_VERSION

## OPTIMIZATION_TARGETS

### IMMEDIATE_ACTIONS
1. Archive legacy auth/config files
2. Archive explicit test/debug files
3. Archive backup versions
4. Move review candidates to review folder

### STRUCTURE_IMPROVEMENTS
1. Consolidate configuration files
2. Optimize CSS includes (reduce from 4 to 2 per page)
3. Implement asset bundling
4. Create unified API structure

### PERFORMANCE_OPTIMIZATIONS
1. Minify CSS/JS files
2. Implement resource caching
3. Optimize database queries
4. Reduce HTTP requests

## TESTING_MATRIX

### CRITICAL_PATHS (Must test after optimization)
1. Login → Dashboard → Reservierungen → Individual Reservation
2. Dashboard → Statistiken
3. Dashboard → Zimmerplan  
4. Dashboard → Tischübersicht → Filtered View
5. Barcode Scanner functionality
6. Mobile navigation
7. Modal displays (tisch-uebersicht iframe)

### API_ENDPOINTS (Must validate)
1. data.php - Main data API
2. getDashboardStats-simple.php - Statistics
3. getReservationDetails.php - Individual reservation
4. get-arrangements.php - HP arrangements
5. save-arrangement-inline.php - Arrangement updates

### EDGE_CASES (Check after cleanup)
1. Authentication failures
2. Database connection errors  
3. Missing file references
4. Broken CSS/JS includes
5. API timeout handling

## ROLLBACK_PLAN

### BACKUP_STRATEGY
1. Full system backup before any changes
2. Git commit before optimization
3. Incremental backups during process
4. Quick rollback script preparation

### VALIDATION_CHECKLIST
- [ ] All primary entry points functional
- [ ] Authentication system working
- [ ] Database connections established  
- [ ] All core pages loading
- [ ] CSS styling intact
- [ ] JavaScript functionality preserved
- [ ] API endpoints responding
- [ ] Special modules operational
- [ ] Mobile compatibility maintained
- [ ] Performance not degraded

---
Generated by automated system analysis
Last updated: August 10, 2025
