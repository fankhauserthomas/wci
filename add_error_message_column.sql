-- Update der Queue-Tabellen um error_message Spalte hinzuzufügen

-- Für lokale Queue-Tabelle
ALTER TABLE sync_queue_local 
ADD COLUMN IF NOT EXISTS error_message TEXT DEFAULT NULL;

-- Für remote Queue-Tabelle  
ALTER TABLE sync_queue_remote 
ADD COLUMN IF NOT EXISTS error_message TEXT DEFAULT NULL;

-- Index für bessere Performance bei Failed-Status
CREATE INDEX IF NOT EXISTS idx_sync_queue_local_status_attempts 
ON sync_queue_local(status, attempts);

CREATE INDEX IF NOT EXISTS idx_sync_queue_remote_status_attempts 
ON sync_queue_remote(status, attempts);
