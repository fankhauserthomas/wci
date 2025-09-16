#!/bin/bash
# deploy.sh - WebCheckin Deployment Script

# Konfiguration
SERVER_HOST=""
SERVER_USER=""
SERVER_PATH=""
LOCAL_PATH="."

# Farben für Output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Funktionen
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Konfiguration prüfen
check_config() {
    if [ -z "$SERVER_HOST" ] || [ -z "$SERVER_USER" ] || [ -z "$SERVER_PATH" ]; then
        log_error "Server-Konfiguration nicht vollständig!"
        echo "Bitte bearbeiten Sie die Variablen am Anfang dieser Datei:"
        echo "SERVER_HOST='ihr.server.de'"
        echo "SERVER_USER='username'"
        echo "SERVER_PATH='/pfad/zum/webroot'"
        exit 1
    fi
}

# SSH-Verbindung testen
test_connection() {
    log_info "Teste SSH-Verbindung zu $SERVER_USER@$SERVER_HOST..."
    if ssh -o ConnectTimeout=5 "$SERVER_USER@$SERVER_HOST" "echo 'Verbindung erfolgreich'" > /dev/null 2>&1; then
        log_info "SSH-Verbindung erfolgreich"
        return 0
    else
        log_error "SSH-Verbindung fehlgeschlagen"
        return 1
    fi
}

# Backup erstellen
create_backup() {
    log_info "Erstelle Backup auf dem Server..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        if [ -d '$SERVER_PATH' ]; then
            cp -r '$SERVER_PATH' '${SERVER_PATH}_backup_$(date +%Y%m%d_%H%M%S)'
            echo 'Backup erstellt'
        else
            echo 'Zielverzeichnis existiert nicht, erstelle es...'
            mkdir -p '$SERVER_PATH'
        fi
    "
}

# Dateien synchronisieren
sync_files() {
    log_info "Synchronisiere Dateien..."
    
    # Ausschließungen definieren
    EXCLUDE_FILE=$(mktemp)
    cat > "$EXCLUDE_FILE" << EOF
.git/
.vscode/
node_modules/
*.log
.env
.htaccess.disabled
*_backup_*
deploy.sh
README.md
EOF

    # rsync mit Ausschließungen
    rsync -avz --delete \
        --exclude-from="$EXCLUDE_FILE" \
        --progress \
        "$LOCAL_PATH/" \
        "$SERVER_USER@$SERVER_HOST:$SERVER_PATH/"
    
    rm "$EXCLUDE_FILE"
    
    if [ $? -eq 0 ]; then
        log_info "Dateien erfolgreich synchronisiert"
    else
        log_error "Fehler beim Synchronisieren der Dateien"
        exit 1
    fi
}

# Berechtigungen setzen
set_permissions() {
    log_info "Setze Dateiberechtigungen..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        cd '$SERVER_PATH'
        find . -type f -name '*.php' -exec chmod 644 {} \;
        find . -type f -name '*.html' -exec chmod 644 {} \;
        find . -type f -name '*.css' -exec chmod 644 {} \;
        find . -type f -name '*.js' -exec chmod 644 {} \;
        find . -type d -exec chmod 755 {} \;
        echo 'Berechtigungen gesetzt'
    "
}

# Landing Page konfigurieren
configure_landing_page() {
    log_info "Konfiguriere Landing Page..."
    ssh "$SERVER_USER@$SERVER_HOST" "
        cd '$SERVER_PATH'
        
        echo 'Landing Page (index.php) bereits vorhanden'
        
        # .htaccess für saubere URLs (falls nicht existiert)
        if [ ! -f '.htaccess' ]; then
            cat > .htaccess << 'HTACCESS_EOF'
# WebCheckin Landing Page Configuration
DirectoryIndex index.php

# Security Headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection \"1; mode=block\"
    Header always set Referrer-Policy \"strict-origin-when-cross-origin\"
</IfModule>

# Cache Control für statische Dateien
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType text/css \"access plus 1 month\"
    ExpiresByType application/javascript \"access plus 1 month\"
    ExpiresByType image/png \"access plus 1 month\"
    ExpiresByType image/jpg \"access plus 1 month\"
    ExpiresByType image/jpeg \"access plus 1 month\"
    ExpiresByType image/gif \"access plus 1 month\"
</IfModule>

# Kompression aktivieren
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
</IfModule>
HTACCESS_EOF
            echo '.htaccess für Landing Page erstellt'
        fi
    "
}

# Deployment-Status prüfen
check_deployment() {
    log_info "Prüfe Deployment..."
    
    # HTTP-Status prüfen
    if command -v curl > /dev/null; then
        HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://$SERVER_HOST/")
        if [ "$HTTP_STATUS" = "200" ]; then
            log_info "Webseite ist erreichbar (HTTP $HTTP_STATUS)"
        else
            log_warn "Webseite Status: HTTP $HTTP_STATUS"
        fi
    fi
    
    # Wichtige Dateien prüfen
    ssh "$SERVER_USER@$SERVER_HOST" "
        cd '$SERVER_PATH'
        echo 'Dateien-Check:'
        [ -f 'index.php' ] && echo '✓ index.php' || echo '✗ index.php fehlt'
        [ -f 'statistiken.html' ] && echo '✓ statistiken.html' || echo '✗ statistiken.html fehlt'
        [ -f 'reservierungen.html' ] && echo '✓ reservierungen.html' || echo '✗ reservierungen.html fehlt'
        [ -f 'include/style.css' ] && echo '✓ style.css' || echo '✗ style.css fehlt'
        [ -d 'js' ] && echo '✓ js/ Verzeichnis' || echo '✗ js/ Verzeichnis fehlt'
    "
}

# Hauptfunktion
main() {
    log_info "WebCheckin Deployment gestartet..."
    
    # Konfiguration prüfen
    check_config
    
    # SSH-Verbindung testen
    if ! test_connection; then
        exit 1
    fi
    
    # Backup erstellen
    create_backup
    
    # Dateien synchronisieren
    sync_files
    
    # Berechtigungen setzen
    set_permissions
    
    # Landing Page konfigurieren
    configure_landing_page
    
    # Deployment prüfen
    check_deployment
    
    log_info "Deployment abgeschlossen!"
    echo ""
    echo "Nächste Schritte:"
    echo "1. Besuchen Sie http://$SERVER_HOST/ für die Landing Page"
    echo "2. Testen Sie alle Funktionen"
    echo "3. Passen Sie ggf. Datenbankverbindungen an"
}

# Parameter verarbeiten
case "$1" in
    "test")
        check_config
        test_connection
        ;;
    "sync")
        check_config
        test_connection && sync_files
        ;;
    "configure")
        check_config
        test_connection && configure_landing_page
        ;;
    *)
        main
        ;;
esac
