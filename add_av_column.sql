-- SQL Script um die neue 'av' Spalte zur Tabelle AV-ResNamen hinzuzufügen
-- Position: nach der 'guide' Spalte

USE booking_franzsen;

-- Füge die neue 'av' Spalte als boolean nach 'guide' hinzu
ALTER TABLE `AV-ResNamen` 
ADD COLUMN `av` BOOLEAN DEFAULT FALSE 
AFTER `guide`;

-- Zeige die neue Tabellenstruktur
DESCRIBE `AV-ResNamen`;
