-- Add rawName and Info columns to prt_queue table
-- This script adds the necessary columns for guest name and room information

ALTER TABLE `prt_queue` 
ADD COLUMN `rawName` VARCHAR(255) DEFAULT '' COMMENT 'Guest name (Nachname + Vorname)',
ADD COLUMN `Info` TEXT DEFAULT '' COMMENT 'Room information with floor details';
