-- SQL Script zum Hinzufügen der NoShow-Spalte zur AV-ResNamen Tabelle
-- Ausführen auf BEIDEN Datenbanken (lokal und remote)

USE booking_franzsen;

-- Prüfe ob Spalte bereits existiert
SELECT COLUMN_NAME 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'booking_franzsen' 
  AND TABLE_NAME = 'AV-ResNamen' 
  AND COLUMN_NAME = 'NoShow';

-- Füge NoShow-Spalte hinzu (nur wenn sie nicht existiert)
ALTER TABLE `AV-ResNamen` 
ADD COLUMN `NoShow` BOOLEAN DEFAULT FALSE
COMMENT 'Markiert ob Gast als No-Show gilt';

-- Prüfe die neue Struktur
DESCRIBE `AV-ResNamen`;

-- Beispiel-Update (optional - alle auf FALSE setzen)
-- UPDATE `AV-ResNamen` SET `NoShow` = FALSE WHERE `NoShow` IS NULL;
