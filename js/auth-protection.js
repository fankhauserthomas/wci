// Frontend Authentication Utility
class AuthProtection {
    static async init() {
        await this.checkAuth();
        this.setupSessionMonitoring();
    }
    
    static async checkAuth() {
        try {
            const response = await fetch('checkAuth.php');
            if (response.status === 401) {
                this.redirectToLogin();
                return false;
            }
            return true;
        } catch (error) {
            console.error('Auth check failed:', error);
            this.redirectToLogin();
            return false;
        }
    }
    
    static redirectToLogin() {
        const target = 'login.html';
        if (!window.location.pathname.endsWith(target)) {
            window.location.href = target;
        } else {
            window.location.reload();
        }
    }
    
    static async logout() {
        try {
            const response = await fetch('logout.php', { cache: 'no-cache' });
            if (response.redirected) {
                window.location.href = response.url;
                return;
            }

            if (response.ok) {
                const payload = await response.json().catch(() => ({}));
                if (payload.redirect) {
                    window.location.href = payload.redirect;
                    return;
                }
            }
        } catch (error) {
            console.error('Logout error:', error);
        }
        this.redirectToLogin();
    }
    
    static setupSessionMonitoring() {
        // Check session every 5 minutes
        setInterval(async () => {
            const isValid = await this.checkAuth();
            if (!isValid) {
                alert('Ihre Session ist abgelaufen. Sie werden zur Anmeldung weitergeleitet.');
            }
        }, 5 * 60 * 1000);
    }
    
    static async fetchWithAuth(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'Cache-Control': 'no-cache',
                ...options.headers
            }
        });
        
        if (response.status === 401) {
            this.redirectToLogin();
            throw new Error('Authentication required');
        }
        
        return response;
    }
}

// Auto-initialize on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => AuthProtection.init());
} else {
    AuthProtection.init();
}
