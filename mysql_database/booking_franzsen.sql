-- phpMyAdmin SQL Dump
-- version 4.9.10
-- https://www.phpmyadmin.net/
--
-- Host: gexile.han-solo.net
-- Erstellungszeit: 05. Jul 2025 um 10:14
-- Server-Version: 5.6.45
-- PHP-Version: 7.0.32

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `booking_franzsen`
--

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf1`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf1` (
`uid` double
,`anz` bigint(21)
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_Angebote`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_Angebote` (
`ID` int(11)
,`email` varchar(50)
,`status` double
,`vorname` varchar(50)
,`name` varchar(50)
,`timestamp` datetime
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_Covid_1`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_Covid_1` (
`ID` int(11)
,`Name` tinytext
,`email` tinytext
,`tel` tinytext
,`Anz` int(11)
,`Tisch` int(11)
,`checkin` time
,`Datum` date
,`Dauer` double
,`Signature` text
,`cin` datetime
,`cout` datetime
,`cob` int(1)
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_Liste1`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_Liste1` (
`email` varchar(50)
,`nam` varchar(101)
,`resid` int(11)
,`anreise` date
,`abreise` date
,`anzahl` double
,`logis` varchar(50)
,`lid` int(11)
,`arr` varchar(50)
,`aid` int(11)
,`bem` mediumtext
,`timestamp` datetime
,`status` double
,`offen` tinyint(4)
,`userid` double
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_ResComDates`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_ResComDates` (
`ID` int(11)
,`AnzahlvonID` bigint(21)
,`anreise` date
,`abreise` date
,`Minvonvon` date
,`Maxvonbis` date
,`DifAn` bigint(10)
,`DifAb` bigint(10)
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_Resdet1`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_Resdet1` (
`id` int(11)
,`von` date
,`bis` date
,`anz` double
,`lid` int(11)
,`lkbez` tinytext
,`lbez` varchar(50)
,`lbem` text
,`lbem1` text
,`aid` int(11)
,`akbez` tinytext
,`abez` varchar(50)
,`abem` text
,`abem1` text
,`bem` text
,`resid` double
,`usr` double
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `Abf_ResNamen`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `Abf_ResNamen` (
`ID` int(11)
,`email` varchar(50)
,`anreise` date
,`abreise` date
,`anz` double
,`anz_inp` bigint(21)
,`status` double
,`remdate` date
);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `Ages`
--

CREATE TABLE `Ages` (
  `id` int(11) NOT NULL,
  `nr` int(11) NOT NULL,
  `bez` text NOT NULL,
  `kkbez` text NOT NULL,
  `von` int(11) NOT NULL,
  `bis` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `arr`
--

CREATE TABLE `arr` (
  `ID` int(11) NOT NULL,
  `bez` varchar(50) DEFAULT NULL,
  `bem` text NOT NULL,
  `bem1` text NOT NULL,
  `sort` double DEFAULT NULL,
  `kbez` tinytext NOT NULL,
  `pbez` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-Res`
--

CREATE TABLE `AV-Res` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT '0',
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `bem_av` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL,
  `origin` bigint(20) DEFAULT '0',
  `erinnert` timestamp NULL DEFAULT NULL,
  `anz_erinnert` int(11) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

--
-- Trigger `AV-Res`
--
DELIMITER $$
CREATE TRIGGER `trg_av_res_after_delete` AFTER DELETE ON `AV-Res` FOR EACH ROW BEGIN
  INSERT INTO av_res_audit (
    id, av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
    `gruppe`, bem, bem_av, nachname, vorname, handy, email, msgid, storno,
    `timestamp`, internal, sync, vorgang, email_date, arr, id64, card, hund, origin,
    AuditDateTime, AuditAction
  ) VALUES (
    OLD.id, OLD.av_id, OLD.anreise, OLD.abreise, OLD.lager, OLD.betten,
    OLD.dz, OLD.sonder, OLD.hp, OLD.vegi, OLD.`gruppe`, OLD.bem, OLD.bem_av,
    OLD.nachname, OLD.vorname, OLD.handy, OLD.email, OLD.msgid, OLD.storno,
    OLD.`timestamp`, OLD.internal, OLD.sync, OLD.vorgang, OLD.email_date,
    OLD.arr, OLD.id64, OLD.card, OLD.hund, OLD.origin,
    NOW(), 'DELETE'
  );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_av_res_after_insert` AFTER INSERT ON `AV-Res` FOR EACH ROW BEGIN
  INSERT INTO av_res_audit (
    id, av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
    `gruppe`, bem, bem_av, nachname, vorname, handy, email, msgid, storno,
    `timestamp`, internal, sync, vorgang, email_date, arr, id64, card, hund, origin,
    AuditDateTime, AuditAction
  ) VALUES (
    NEW.id, NEW.av_id, NEW.anreise, NEW.abreise, NEW.lager, NEW.betten,
    NEW.dz, NEW.sonder, NEW.hp, NEW.vegi, NEW.`gruppe`, NEW.bem, NEW.bem_av,
    NEW.nachname, NEW.vorname, NEW.handy, NEW.email, NEW.msgid, NEW.storno,
    NEW.`timestamp`, NEW.internal, NEW.sync, NEW.vorgang, NEW.email_date,
    NEW.arr, NEW.id64, NEW.card, NEW.hund, NEW.origin,
    NOW(), 'INSERT'
  );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_av_res_after_update` AFTER UPDATE ON `AV-Res` FOR EACH ROW BEGIN
  INSERT INTO av_res_audit (
    id, av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
    `gruppe`, bem, bem_av, nachname, vorname, handy, email, msgid, storno,
    `timestamp`, internal, sync, vorgang, email_date, arr, id64, card, hund, origin,
    AuditDateTime, AuditAction
  ) VALUES (
    NEW.id, NEW.av_id, NEW.anreise, NEW.abreise, NEW.lager, NEW.betten,
    NEW.dz, NEW.sonder, NEW.hp, NEW.vegi, NEW.`gruppe`, NEW.bem, NEW.bem_av,
    NEW.nachname, NEW.vorname, NEW.handy, NEW.email, NEW.msgid, NEW.storno,
    NEW.`timestamp`, NEW.internal, NEW.sync, NEW.vorgang, NEW.email_date,
    NEW.arr, NEW.id64, NEW.card, NEW.hund, NEW.origin,
    NOW(), 'UPDATE'
  );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-Res-imp`
--

CREATE TABLE `AV-Res-imp` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT NULL,
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `bem_av` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-Res-n8n`
--

CREATE TABLE `AV-Res-n8n` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT NULL,
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `bem_av` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-Res-Save`
--

CREATE TABLE `AV-Res-Save` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT NULL,
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-ResNamen`
--

CREATE TABLE `AV-ResNamen` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `vorname` text,
  `nachname` text,
  `gebdat` date DEFAULT NULL,
  `ageGrp` int(11) NOT NULL DEFAULT '0',
  `herkunft` int(11) DEFAULT NULL,
  `bem` text,
  `guide` tinyint(1) NOT NULL DEFAULT '0',
  `arr` int(11) NOT NULL DEFAULT '0',
  `diet` int(11) NOT NULL DEFAULT '0',
  `checked_in` datetime DEFAULT NULL,
  `checked_out` datetime DEFAULT NULL,
  `HasCard` tinyint(1) NOT NULL DEFAULT '0',
  `CardName` text NOT NULL,
  `autoinsert` tinyint(1) NOT NULL DEFAULT '0',
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-ResNamen_Save`
--

CREATE TABLE `AV-ResNamen_Save` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `vorname` text NOT NULL,
  `nachname` text NOT NULL,
  `gebdat` date DEFAULT NULL,
  `herkunft` int(11) DEFAULT NULL,
  `bem` text
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV-ResNamen_Save1`
--

CREATE TABLE `AV-ResNamen_Save1` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `vorname` text,
  `nachname` text,
  `gebdat` date DEFAULT NULL,
  `herkunft` int(11) DEFAULT NULL,
  `bem` text,
  `guide` tinyint(1) NOT NULL DEFAULT '0',
  `arr` int(11) NOT NULL DEFAULT '0',
  `checked_in` datetime DEFAULT NULL,
  `checked_out` datetime DEFAULT NULL,
  `autoinsert` tinyint(1) NOT NULL DEFAULT '0',
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `av_belegung`
--

CREATE TABLE `av_belegung` (
  `datum` date NOT NULL,
  `free_place` int(11) NOT NULL,
  `hut_status` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV_local_Res`
--

CREATE TABLE `AV_local_Res` (
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT NULL,
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `bem_av` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `AV_ResDet`
--

CREATE TABLE `AV_ResDet` (
  `ID` bigint(20) NOT NULL,
  `tab` text NOT NULL,
  `zimID` int(11) NOT NULL,
  `resid` bigint(20) NOT NULL,
  `bez` text NOT NULL,
  `anz` int(11) NOT NULL,
  `von` date NOT NULL,
  `bis` date NOT NULL,
  `arr` int(11) NOT NULL,
  `col` text NOT NULL,
  `note` text NOT NULL,
  `dx` int(11) NOT NULL,
  `dy` int(11) NOT NULL,
  `ParentID` bigint(20) NOT NULL,
  `hund` tinyint(1) NOT NULL,
  `ts_auto` timestamp NULL DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `av_res_audit`
--

CREATE TABLE `av_res_audit` (
  `audit_id` bigint(20) NOT NULL,
  `AuditAction` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `AuditDateTime` datetime NOT NULL,
  `id` bigint(20) NOT NULL,
  `av_id` bigint(20) NOT NULL,
  `anreise` datetime DEFAULT NULL,
  `abreise` datetime DEFAULT NULL,
  `lager` int(11) DEFAULT NULL,
  `betten` int(11) DEFAULT NULL,
  `dz` int(11) DEFAULT NULL,
  `sonder` int(11) DEFAULT NULL,
  `hp` int(11) DEFAULT NULL,
  `vegi` int(11) DEFAULT NULL,
  `gruppe` text,
  `bem` text,
  `bem_av` text,
  `nachname` text,
  `vorname` text,
  `handy` text,
  `email` text,
  `msgid` text,
  `storno` tinyint(1) DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `internal` text,
  `sync` int(11) DEFAULT NULL,
  `vorgang` text NOT NULL,
  `email_date` datetime DEFAULT NULL,
  `arr` int(11) NOT NULL,
  `id64` text NOT NULL,
  `card` text,
  `hund` tinyint(1) NOT NULL,
  `origin` bigint(20) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `a_av_belegung`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `a_av_belegung` (
`Datum` date
,`Lager` decimal(32,0)
,`Betten` decimal(32,0)
,`DZ` decimal(32,0)
,`Sonder` decimal(32,0)
,`Gesamt` decimal(35,0)
,`Hunde` decimal(25,0)
,`Ein_Nacht_Plaetze` decimal(35,0)
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `a_av_res`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `a_av_res` (
`id` bigint(20)
,`av_id` bigint(20)
,`anreise` datetime
,`abreise` datetime
,`lager` int(11)
,`betten` int(11)
,`dz` int(11)
,`sonder` int(11)
,`hp` int(11)
,`vegi` int(11)
,`gruppe` text
,`bem` text
,`nachname` text
,`vorname` text
,`handy` text
,`email` text
,`msgid` text
,`storno` tinyint(1)
,`timestamp` datetime
,`internal` text
,`sync` int(11)
,`vorgang` text
,`email_date` datetime
,`arr` int(11)
,`card` text
);

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `a_av_res_all`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `a_av_res_all` (
`tab` varchar(5)
,`tabID` bigint(20)
,`id` bigint(20)
,`av_id` bigint(20)
,`anreise` datetime
,`abreise` datetime
,`lager` int(11)
,`betten` int(11)
,`dz` int(11)
,`sonder` int(11)
,`hp` int(11)
,`vegi` int(11)
,`gruppe` text
,`bem` text
,`nachname` text
,`vorname` text
,`handy` text
,`email` text
,`msgid` text
,`storno` tinyint(4)
,`timestamp` datetime
,`internal` text
,`sync` int(11)
,`vorgang` text
,`email_date` datetime
,`arr` int(11)
,`id64` text
,`card` text
);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `BelTMP`
--

CREATE TABLE `BelTMP` (
  `Tag` date NOT NULL,
  `P` int(11) NOT NULL,
  `L` int(11) NOT NULL,
  `M` int(11) NOT NULL,
  `D` int(11) NOT NULL,
  `Sum` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `BelTMPSSH`
--

CREATE TABLE `BelTMPSSH` (
  `Tag` date NOT NULL,
  `P` int(11) NOT NULL,
  `L` int(11) NOT NULL,
  `M` int(11) NOT NULL,
  `D` int(11) NOT NULL,
  `Sum` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `BelTMP_2`
--

CREATE TABLE `BelTMP_2` (
  `Tag` date NOT NULL,
  `AG` int(11) NOT NULL,
  `AH` int(11) NOT NULL,
  `K` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `CartPrinters`
--

CREATE TABLE `CartPrinters` (
  `id` int(11) NOT NULL,
  `bez` text NOT NULL,
  `kbez` text NOT NULL,
  `dymo` tinyint(1) NOT NULL,
  `PrtName` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `countries`
--

CREATE TABLE `countries` (
  `id` int(11) NOT NULL,
  `cid` int(11) NOT NULL,
  `country` text NOT NULL,
  `sort` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `COVID`
--

CREATE TABLE `COVID` (
  `ID` int(11) NOT NULL,
  `Name` tinytext NOT NULL,
  `email` tinytext NOT NULL,
  `tel` tinytext NOT NULL,
  `Anz` int(11) NOT NULL,
  `Tisch` int(11) NOT NULL,
  `checkin` time NOT NULL,
  `Datum` date NOT NULL,
  `Dauer` double NOT NULL,
  `Signature` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `COVID_Tisch`
--

CREATE TABLE `COVID_Tisch` (
  `ID` int(11) NOT NULL,
  `Bez` tinytext NOT NULL,
  `sort` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `diet`
--

CREATE TABLE `diet` (
  `id` int(11) NOT NULL,
  `bez` text NOT NULL,
  `sort` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `kalender`
--

CREATE TABLE `kalender` (
  `dt` date NOT NULL,
  `jahr` smallint(6) NOT NULL,
  `monat` tinyint(4) NOT NULL,
  `tag` tinyint(4) NOT NULL,
  `wochentag` tinyint(4) NOT NULL,
  `iso_kw` tinyint(4) NOT NULL,
  `quartal` tinyint(4) NOT NULL,
  `monat_name` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `loginlog`
--

CREATE TABLE `loginlog` (
  `ID` int(11) NOT NULL,
  `user` varchar(100) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `zeit` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `logis`
--

CREATE TABLE `logis` (
  `ID` int(11) NOT NULL,
  `bez` varchar(50) DEFAULT NULL,
  `bem` text NOT NULL,
  `bem1` text NOT NULL,
  `sort` double DEFAULT NULL,
  `kbez` tinytext NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nachrichten`
--

CREATE TABLE `nachrichten` (
  `ID` int(11) NOT NULL,
  `res` double NOT NULL,
  `von` varchar(50) DEFAULT NULL,
  `zeit` datetime DEFAULT NULL,
  `betreff` varchar(100) DEFAULT NULL,
  `nachricht` mediumtext,
  `gesehen` tinyint(4) DEFAULT NULL,
  `user` double DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nomail`
--

CREATE TABLE `nomail` (
  `id` int(11) NOT NULL,
  `no_email` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `origin`
--

CREATE TABLE `origin` (
  `id` int(11) NOT NULL,
  `cid` int(11) DEFAULT NULL,
  `country` text,
  `sort` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__bookmark`
--

CREATE TABLE `pma__bookmark` (
  `id` int(10) UNSIGNED NOT NULL,
  `dbase` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
  `user` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
  `label` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `query` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Bookmarks';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__central_columns`
--

CREATE TABLE `pma__central_columns` (
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `col_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `col_type` varchar(64) COLLATE utf8_bin NOT NULL,
  `col_length` text COLLATE utf8_bin,
  `col_collation` varchar(64) COLLATE utf8_bin NOT NULL,
  `col_isNull` tinyint(1) NOT NULL,
  `col_extra` varchar(255) COLLATE utf8_bin DEFAULT '',
  `col_default` text COLLATE utf8_bin
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Central list of columns';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__column_info`
--

CREATE TABLE `pma__column_info` (
  `id` int(5) UNSIGNED NOT NULL,
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `column_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `comment` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `mimetype` varchar(255) CHARACTER SET utf8 NOT NULL DEFAULT '',
  `transformation` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
  `transformation_options` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
  `input_transformation` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
  `input_transformation_options` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Column information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__designer_settings`
--

CREATE TABLE `pma__designer_settings` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `settings_data` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Settings related to Designer';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__export_templates`
--

CREATE TABLE `pma__export_templates` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `export_type` varchar(10) COLLATE utf8_bin NOT NULL,
  `template_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `template_data` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved export templates';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__favorite`
--

CREATE TABLE `pma__favorite` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `tables` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Favorite tables';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__history`
--

CREATE TABLE `pma__history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `db` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `table` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `timevalue` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sqlquery` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='SQL history for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__navigationhiding`
--

CREATE TABLE `pma__navigationhiding` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `item_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `item_type` varchar(64) COLLATE utf8_bin NOT NULL,
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Hidden items of navigation tree';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__pdf_pages`
--

CREATE TABLE `pma__pdf_pages` (
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `page_nr` int(10) UNSIGNED NOT NULL,
  `page_descr` varchar(50) CHARACTER SET utf8 NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='PDF relation pages for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__recent`
--

CREATE TABLE `pma__recent` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `tables` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Recently accessed tables';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__relation`
--

CREATE TABLE `pma__relation` (
  `master_db` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `master_table` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `master_field` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `foreign_db` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `foreign_table` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `foreign_field` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Relation table';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__savedsearches`
--

CREATE TABLE `pma__savedsearches` (
  `id` int(5) UNSIGNED NOT NULL,
  `username` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `search_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `search_data` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Saved searches';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__table_coords`
--

CREATE TABLE `pma__table_coords` (
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `pdf_page_number` int(11) NOT NULL DEFAULT '0',
  `x` float UNSIGNED NOT NULL DEFAULT '0',
  `y` float UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table coordinates for phpMyAdmin PDF output';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__table_info`
--

CREATE TABLE `pma__table_info` (
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT '',
  `display_field` varchar(64) COLLATE utf8_bin NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Table information for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__table_uiprefs`
--

CREATE TABLE `pma__table_uiprefs` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `prefs` text COLLATE utf8_bin NOT NULL,
  `last_update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Tables'' UI preferences';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__tracking`
--

CREATE TABLE `pma__tracking` (
  `db_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `table_name` varchar(64) COLLATE utf8_bin NOT NULL,
  `version` int(10) UNSIGNED NOT NULL,
  `date_created` datetime NOT NULL,
  `date_updated` datetime NOT NULL,
  `schema_snapshot` text COLLATE utf8_bin NOT NULL,
  `schema_sql` text COLLATE utf8_bin,
  `data_sql` longtext COLLATE utf8_bin,
  `tracking` set('UPDATE','REPLACE','INSERT','DELETE','TRUNCATE','CREATE DATABASE','ALTER DATABASE','DROP DATABASE','CREATE TABLE','ALTER TABLE','RENAME TABLE','DROP TABLE','CREATE INDEX','DROP INDEX','CREATE VIEW','ALTER VIEW','DROP VIEW') COLLATE utf8_bin DEFAULT NULL,
  `tracking_active` int(1) UNSIGNED NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Database changes tracking for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__userconfig`
--

CREATE TABLE `pma__userconfig` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `timevalue` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `config_data` text COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User preferences storage for phpMyAdmin';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__usergroups`
--

CREATE TABLE `pma__usergroups` (
  `usergroup` varchar(64) COLLATE utf8_bin NOT NULL,
  `tab` varchar(64) COLLATE utf8_bin NOT NULL,
  `allowed` enum('Y','N') COLLATE utf8_bin NOT NULL DEFAULT 'N'
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='User groups with configured menu items';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `pma__users`
--

CREATE TABLE `pma__users` (
  `username` varchar(64) COLLATE utf8_bin NOT NULL,
  `usergroup` varchar(64) COLLATE utf8_bin NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin COMMENT='Users and their assignments to user groups';

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `prt_queue`
--

CREATE TABLE `prt_queue` (
  `id` int(11) NOT NULL,
  `rn_id` int(11) NOT NULL,
  `prt` text NOT NULL,
  `done` tinyint(1) NOT NULL,
  `ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `res`
--

CREATE TABLE `res` (
  `ID` int(11) NOT NULL,
  `user` double DEFAULT NULL,
  `anreise` date DEFAULT NULL,
  `abreise` date DEFAULT NULL,
  `anzahl` double DEFAULT NULL,
  `logis` double DEFAULT NULL,
  `arr` double DEFAULT NULL,
  `bem` mediumtext,
  `status` double DEFAULT NULL,
  `timestamp` datetime DEFAULT NULL,
  `akzept` tinyint(4) DEFAULT NULL,
  `offen` tinyint(4) DEFAULT NULL,
  `rem_resname` date DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `resdet1`
--

CREATE TABLE `resdet1` (
  `ID` int(11) NOT NULL,
  `res` double DEFAULT NULL,
  `von` date DEFAULT NULL,
  `bis` date DEFAULT NULL,
  `anz` double DEFAULT NULL,
  `logis` double DEFAULT NULL,
  `arr` double DEFAULT NULL,
  `bem` text NOT NULL,
  `user` double DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `resnamen`
--

CREATE TABLE `resnamen` (
  `ID` int(11) NOT NULL,
  `res` double DEFAULT NULL,
  `Vorname` varchar(50) DEFAULT NULL,
  `Nachname` varchar(50) DEFAULT NULL,
  `alter` int(11) DEFAULT NULL,
  `tel` text NOT NULL,
  `email` text NOT NULL,
  `logis` double DEFAULT NULL,
  `arr` double DEFAULT NULL,
  `von` date DEFAULT NULL,
  `bis` date DEFAULT NULL,
  `dieaet` mediumtext,
  `bem` mediumtext,
  `user` double DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `status`
--

CREATE TABLE `status` (
  `ID` int(11) NOT NULL,
  `bez` varchar(50) DEFAULT NULL,
  `sort` double DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `templates`
--

CREATE TABLE `templates` (
  `ID` int(11) NOT NULL,
  `name` tinytext NOT NULL,
  `wert` text NOT NULL,
  `wert2` text NOT NULL,
  `sort` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `user`
--

CREATE TABLE `user` (
  `ID` int(11) NOT NULL,
  `email` varchar(50) DEFAULT NULL,
  `nachname` varchar(50) NOT NULL,
  `pwd` varchar(50) DEFAULT NULL,
  `active` int(11) DEFAULT NULL,
  `registerdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_msg_date` timestamp NOT NULL,
  `last_msg_key` tinytext NOT NULL,
  `vorname` varchar(50) DEFAULT NULL,
  `fix` tinyint(1) NOT NULL,
  `reset_token` varchar(50) DEFAULT NULL,
  `reset_date` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `userdet`
--

CREATE TABLE `userdet` (
  `ID` int(11) NOT NULL,
  `user` double DEFAULT NULL,
  `vorname` varchar(50) DEFAULT NULL,
  `name` varchar(50) DEFAULT NULL,
  `strasse` varchar(50) DEFAULT NULL,
  `land` varchar(50) DEFAULT NULL,
  `plz` varchar(50) DEFAULT NULL,
  `ort` varchar(50) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `newsletter` tinyint(1) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `user_no_res`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE `user_no_res` (
`user` int(11)
,`registerdate` timestamp
,`email` varchar(50)
,`last_msg_date` timestamp
,`last_msg_key` tinytext
,`tdiffreg` bigint(21)
,`tdiffmsg` bigint(21)
,`act` int(11)
,`fix` tinyint(1)
);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `zp_etage`
--

CREATE TABLE `zp_etage` (
  `id` int(11) NOT NULL,
  `nr` int(11) NOT NULL,
  `bez` text NOT NULL,
  `stockwerk` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `zp_zimmer`
--

CREATE TABLE `zp_zimmer` (
  `id` int(10) UNSIGNED NOT NULL,
  `caption` varchar(100) NOT NULL COMMENT 'z.B. "Zimmer 1" oder "nicht zugewiesen"',
  `etage` smallint(6) NOT NULL,
  `kapazitaet` smallint(6) NOT NULL,
  `kategorie` varchar(50) NOT NULL,
  `col` char(9) NOT NULL COMMENT 'Hintergrundfarbe als Hex, z.B. "#AARRGGBB"',
  `px` int(11) NOT NULL,
  `py` int(11) NOT NULL,
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `sort` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf1`
--
DROP TABLE IF EXISTS `Abf1`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf1`  AS SELECT `res`.`user` AS `uid`, count(`res`.`user`) AS `anz` FROM `res` GROUP BY `res`.`user``user` ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_Angebote`
--
DROP TABLE IF EXISTS `Abf_Angebote`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_Angebote`  AS SELECT `res`.`ID` AS `ID`, `user`.`email` AS `email`, `res`.`status` AS `status`, `userdet`.`vorname` AS `vorname`, `userdet`.`name` AS `name`, `res`.`timestamp` AS `timestamp` FROM (`userdet` join ((`res` join `user` on((`res`.`user` = `user`.`ID`))) join `nachrichten` on((`res`.`ID` = `nachrichten`.`res`))) on((`userdet`.`ID` = `res`.`user`))) GROUP BY `res`.`ID`, `user`.`email`, `res`.`status`, `userdet`.`vorname`, `userdet`.`name`, `res`.`timestamp` HAVING (`res`.`status` = 5)) ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_Covid_1`
--
DROP TABLE IF EXISTS `Abf_Covid_1`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_Covid_1`  AS SELECT `COVID`.`ID` AS `ID`, `COVID`.`Name` AS `Name`, `COVID`.`email` AS `email`, `COVID`.`tel` AS `tel`, `COVID`.`Anz` AS `Anz`, `COVID`.`Tisch` AS `Tisch`, `COVID`.`checkin` AS `checkin`, `COVID`.`Datum` AS `Datum`, `COVID`.`Dauer` AS `Dauer`, `COVID`.`Signature` AS `Signature`, str_to_date(concat(`COVID`.`Datum`,' ',`COVID`.`checkin`),'%Y-%m-%d %H:%i:%s') AS `cin`, (str_to_date(concat(`COVID`.`Datum`,' ',`COVID`.`checkin`),'%Y-%m-%d %H:%i:%s') + interval (`COVID`.`Dauer` * 60) minute) AS `cout`, (((str_to_date(concat(`COVID`.`Datum`,' ',`COVID`.`checkin`),'%Y-%m-%d %H:%i:%s') + interval (`COVID`.`Dauer` * 60) minute) > now()) and (str_to_date(concat(`COVID`.`Datum`,' ',`COVID`.`checkin`),'%Y-%m-%d %H:%i:%s') < now())) AS `cob` FROM `COVID``COVID` ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_Liste1`
--
DROP TABLE IF EXISTS `Abf_Liste1`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_Liste1`  AS SELECT `user`.`email` AS `email`, concat(`userdet`.`vorname`,' ',`userdet`.`name`) AS `nam`, `res`.`ID` AS `resid`, `res`.`anreise` AS `anreise`, `res`.`abreise` AS `abreise`, `res`.`anzahl` AS `anzahl`, `logis`.`bez` AS `logis`, `logis`.`ID` AS `lid`, `arr`.`bez` AS `arr`, `arr`.`ID` AS `aid`, `res`.`bem` AS `bem`, `res`.`timestamp` AS `timestamp`, `res`.`status` AS `status`, `res`.`offen` AS `offen`, `res`.`user` AS `userid` FROM ((((`user` join `res` on((`user`.`ID` = `res`.`user`))) join `logis` on((`res`.`logis` = `logis`.`ID`))) join `arr` on((`res`.`arr` = `arr`.`ID`))) join `userdet` on((`user`.`ID` = `userdet`.`user`))) WHERE (`res`.`offen` = 1)) ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_ResComDates`
--
DROP TABLE IF EXISTS `Abf_ResComDates`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_ResComDates`  AS SELECT `res`.`ID` AS `ID`, count(`resdet1`.`ID`) AS `AnzahlvonID`, `res`.`anreise` AS `anreise`, `res`.`abreise` AS `abreise`, min(`resdet1`.`von`) AS `Minvonvon`, max(`resdet1`.`bis`) AS `Maxvonbis`, min((`res`.`anreise` - `resdet1`.`von`)) AS `DifAn`, min((`res`.`abreise` - `resdet1`.`bis`)) AS `DifAb` FROM (`resdet1` join `res` on((`resdet1`.`res` = `res`.`ID`))) GROUP BY `res`.`ID`, `res`.`anreise`, `res`.`abreise` ORDER BY `res`.`ID` ASC`ID` ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_Resdet1`
--
DROP TABLE IF EXISTS `Abf_Resdet1`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_Resdet1`  AS SELECT `resdet1`.`ID` AS `id`, `resdet1`.`von` AS `von`, `resdet1`.`bis` AS `bis`, `resdet1`.`anz` AS `anz`, `logis`.`ID` AS `lid`, `logis`.`kbez` AS `lkbez`, `logis`.`bez` AS `lbez`, `logis`.`bem` AS `lbem`, `logis`.`bem1` AS `lbem1`, `arr`.`ID` AS `aid`, `arr`.`kbez` AS `akbez`, `arr`.`bez` AS `abez`, `arr`.`bem` AS `abem`, `arr`.`bem1` AS `abem1`, `resdet1`.`bem` AS `bem`, `resdet1`.`res` AS `resid`, `resdet1`.`user` AS `usr` FROM ((`resdet1` join `arr` on((`resdet1`.`arr` = `arr`.`ID`))) join `logis` on((`resdet1`.`logis` = `logis`.`ID`)))) ;

-- --------------------------------------------------------

--
-- Struktur des Views `Abf_ResNamen`
--
DROP TABLE IF EXISTS `Abf_ResNamen`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `Abf_ResNamen`  AS SELECT `res`.`ID` AS `ID`, `user`.`email` AS `email`, `res`.`anreise` AS `anreise`, `res`.`abreise` AS `abreise`, `res`.`anzahl` AS `anz`, count(`resnamen`.`ID`) AS `anz_inp`, `res`.`status` AS `status`, `res`.`rem_resname` AS `remdate` FROM ((`res` join `user` on((`res`.`user` = `user`.`ID`))) left join `resnamen` on((`resnamen`.`res` = `res`.`ID`))) GROUP BY `res`.`ID`, `user`.`email`, `res`.`anreise`, `res`.`abreise`, `res`.`anzahl`, `res`.`status`, `res`.`rem_resname` HAVING (`res`.`status` = 2) ORDER BY `res`.`anreise` ASC`anreise` ;

-- --------------------------------------------------------

--
-- Struktur des Views `a_av_belegung`
--
DROP TABLE IF EXISTS `a_av_belegung`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `a_av_belegung`  AS SELECT `k`.`dt` AS `Datum`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then `r`.`lager` else 0 end)),0) AS `Lager`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then `r`.`betten` else 0 end)),0) AS `Betten`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then `r`.`dz` else 0 end)),0) AS `DZ`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then `r`.`sonder` else 0 end)),0) AS `Sonder`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then (((`r`.`lager` + `r`.`betten`) + `r`.`dz`) + `r`.`sonder`) else 0 end)),0) AS `Gesamt`, coalesce(sum((case when ((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then `r`.`hund` else 0 end)),0) AS `Hunde`, coalesce(sum((case when (((to_days(`r`.`abreise`) - to_days(`r`.`anreise`)) = 1) and (`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date))) then (((`r`.`lager` + `r`.`betten`) + `r`.`dz`) + `r`.`sonder`) else 0 end)),0) AS `Ein_Nacht_Plaetze` FROM (`kalender` `k` left join `AV-Res` `r` on(((`k`.`dt` >= cast(`r`.`anreise` as date)) and (`k`.`dt` < cast(`r`.`abreise` as date)) and (coalesce(`r`.`storno`,0) = 0)))) WHERE ((`k`.`dt` >= (select min(cast(`AV-Res`.`anreise` as date)) from `AV-Res` where (coalesce(`AV-Res`.`storno`,0) = 0))) AND (`k`.`dt` < (select max(cast(`AV-Res`.`abreise` as date)) from `AV-Res` where (coalesce(`AV-Res`.`storno`,0) = 0)))) GROUP BY `k`.`dt` ORDER BY `k`.`dt` ASC`dt` ;

-- --------------------------------------------------------

--
-- Struktur des Views `a_av_res`
--
DROP TABLE IF EXISTS `a_av_res`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `a_av_res`  AS SELECT `t`.`id` AS `id`, `t`.`av_id` AS `av_id`, `t`.`anreise` AS `anreise`, `t`.`abreise` AS `abreise`, `t`.`lager` AS `lager`, `t`.`betten` AS `betten`, `t`.`dz` AS `dz`, `t`.`sonder` AS `sonder`, `t`.`hp` AS `hp`, `t`.`vegi` AS `vegi`, `t`.`gruppe` AS `gruppe`, `t`.`bem` AS `bem`, `t`.`nachname` AS `nachname`, `t`.`vorname` AS `vorname`, `t`.`handy` AS `handy`, `t`.`email` AS `email`, `t`.`msgid` AS `msgid`, `t`.`storno` AS `storno`, `t`.`timestamp` AS `timestamp`, `t`.`internal` AS `internal`, `t`.`sync` AS `sync`, `t`.`vorgang` AS `vorgang`, `t`.`email_date` AS `email_date`, `t`.`arr` AS `arr`, `t`.`card` AS `card` FROM `AV-Res` AS `t` WHERE ((`t`.`vorgang` <> 'storno') AND (`t`.`email_date` = (select max(`t2`.`email_date`) from `AV-Res` `t2` where ((`t2`.`av_id` = `t`.`av_id`) AND (`t2`.`vorgang` <> 'storno')))))) ;

-- --------------------------------------------------------

--
-- Struktur des Views `a_av_res_all`
--
DROP TABLE IF EXISTS `a_av_res_all`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `a_av_res_all`  AS SELECT 'av' AS `tab`, `r`.`av_id` AS `tabID`, `r`.`id` AS `id`, `r`.`av_id` AS `av_id`, `r`.`anreise` AS `anreise`, `r`.`abreise` AS `abreise`, `r`.`lager` AS `lager`, `r`.`betten` AS `betten`, `r`.`dz` AS `dz`, `r`.`sonder` AS `sonder`, `r`.`hp` AS `hp`, `r`.`vegi` AS `vegi`, `r`.`gruppe` AS `gruppe`, `r`.`bem` AS `bem`, `r`.`nachname` AS `nachname`, `r`.`vorname` AS `vorname`, `r`.`handy` AS `handy`, `r`.`email` AS `email`, `r`.`msgid` AS `msgid`, `r`.`storno` AS `storno`, `r`.`timestamp` AS `timestamp`, `r`.`internal` AS `internal`, `r`.`sync` AS `sync`, `r`.`vorgang` AS `vorgang`, `r`.`email_date` AS `email_date`, `r`.`arr` AS `arr`, `r`.`id64` AS `id64`, `r`.`card` AS `card` FROM `AV-Res` AS `r` WHERE ((`r`.`storno` = 0) AND (`r`.`timestamp` = (select max(`r2`.`timestamp`) from `AV-Res` `r2` where ((`r2`.`av_id` = `r`.`av_id`) AND (`r2`.`storno` = 0))))) union all select 'local' AS `tab`,`l`.`id` AS `tabID`,`l`.`id` AS `id`,`l`.`av_id` AS `av_id`,`l`.`anreise` AS `anreise`,`l`.`abreise` AS `abreise`,`l`.`lager` AS `lager`,`l`.`betten` AS `betten`,`l`.`dz` AS `dz`,`l`.`sonder` AS `sonder`,`l`.`hp` AS `hp`,`l`.`vegi` AS `vegi`,`l`.`gruppe` AS `gruppe`,`l`.`bem` AS `bem`,`l`.`nachname` AS `nachname`,`l`.`vorname` AS `vorname`,`l`.`handy` AS `handy`,`l`.`email` AS `email`,`l`.`msgid` AS `msgid`,`l`.`storno` AS `storno`,`l`.`timestamp` AS `timestamp`,`l`.`internal` AS `internal`,`l`.`sync` AS `sync`,`l`.`vorgang` AS `vorgang`,`l`.`email_date` AS `email_date`,`l`.`arr` AS `arr`,`l`.`id64` AS `id64`,`l`.`card` AS `card` from `AV_local_Res` `l` where (`l`.`storno` = 0) ;

-- --------------------------------------------------------

--
-- Struktur des Views `user_no_res`
--
DROP TABLE IF EXISTS `user_no_res`;

CREATE ALGORITHM=UNDEFINED DEFINER=`booking_franzsen`@`gexile.han-solo.net` SQL SECURITY DEFINER VIEW `user_no_res`  AS SELECT `user`.`ID` AS `user`, `user`.`registerdate` AS `registerdate`, `user`.`email` AS `email`, `user`.`last_msg_date` AS `last_msg_date`, `user`.`last_msg_key` AS `last_msg_key`, timestampdiff(HOUR,`user`.`registerdate`,now()) AS `tdiffreg`, timestampdiff(HOUR,`user`.`last_msg_date`,now()) AS `tdiffmsg`, `user`.`active` AS `act`, `user`.`fix` AS `fix` FROM (`user` left join `Abf1` on((`Abf1`.`uid` = `user`.`ID`))) WHERE isnull(`Abf1`.`anz`)) ;

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `Ages`
--
ALTER TABLE `Ages`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `arr`
--
ALTER TABLE `arr`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `AV-Res`
--
ALTER TABLE `AV-Res`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-Res-imp`
--
ALTER TABLE `AV-Res-imp`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-Res-n8n`
--
ALTER TABLE `AV-Res-n8n`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-Res-Save`
--
ALTER TABLE `AV-Res-Save`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-ResNamen`
--
ALTER TABLE `AV-ResNamen`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-ResNamen_Save`
--
ALTER TABLE `AV-ResNamen_Save`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV-ResNamen_Save1`
--
ALTER TABLE `AV-ResNamen_Save1`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `av_belegung`
--
ALTER TABLE `av_belegung`
  ADD PRIMARY KEY (`datum`);

--
-- Indizes für die Tabelle `AV_local_Res`
--
ALTER TABLE `AV_local_Res`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `AV_ResDet`
--
ALTER TABLE `AV_ResDet`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `IDX_AV_ResDet_ParentID` (`ParentID`);

--
-- Indizes für die Tabelle `av_res_audit`
--
ALTER TABLE `av_res_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_av_res_id` (`id`);

--
-- Indizes für die Tabelle `CartPrinters`
--
ALTER TABLE `CartPrinters`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `COVID`
--
ALTER TABLE `COVID`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `COVID_Tisch`
--
ALTER TABLE `COVID_Tisch`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `diet`
--
ALTER TABLE `diet`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `kalender`
--
ALTER TABLE `kalender`
  ADD PRIMARY KEY (`dt`);

--
-- Indizes für die Tabelle `loginlog`
--
ALTER TABLE `loginlog`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `logis`
--
ALTER TABLE `logis`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `nachrichten`
--
ALTER TABLE `nachrichten`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `nomail`
--
ALTER TABLE `nomail`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `origin`
--
ALTER TABLE `origin`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `pma__central_columns`
--
ALTER TABLE `pma__central_columns`
  ADD PRIMARY KEY (`db_name`,`col_name`);

--
-- Indizes für die Tabelle `pma__column_info`
--
ALTER TABLE `pma__column_info`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `db_name` (`db_name`,`table_name`,`column_name`);

--
-- Indizes für die Tabelle `pma__designer_settings`
--
ALTER TABLE `pma__designer_settings`
  ADD PRIMARY KEY (`username`);

--
-- Indizes für die Tabelle `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_user_type_template` (`username`,`export_type`,`template_name`);

--
-- Indizes für die Tabelle `pma__favorite`
--
ALTER TABLE `pma__favorite`
  ADD PRIMARY KEY (`username`);

--
-- Indizes für die Tabelle `pma__history`
--
ALTER TABLE `pma__history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `username` (`username`,`db`,`table`,`timevalue`);

--
-- Indizes für die Tabelle `pma__navigationhiding`
--
ALTER TABLE `pma__navigationhiding`
  ADD PRIMARY KEY (`username`,`item_name`,`item_type`,`db_name`,`table_name`);

--
-- Indizes für die Tabelle `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  ADD PRIMARY KEY (`page_nr`),
  ADD KEY `db_name` (`db_name`);

--
-- Indizes für die Tabelle `pma__recent`
--
ALTER TABLE `pma__recent`
  ADD PRIMARY KEY (`username`);

--
-- Indizes für die Tabelle `pma__relation`
--
ALTER TABLE `pma__relation`
  ADD PRIMARY KEY (`master_db`,`master_table`,`master_field`),
  ADD KEY `foreign_field` (`foreign_db`,`foreign_table`);

--
-- Indizes für die Tabelle `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `u_savedsearches_username_dbname` (`username`,`db_name`,`search_name`);

--
-- Indizes für die Tabelle `pma__table_coords`
--
ALTER TABLE `pma__table_coords`
  ADD PRIMARY KEY (`db_name`,`table_name`,`pdf_page_number`);

--
-- Indizes für die Tabelle `pma__table_info`
--
ALTER TABLE `pma__table_info`
  ADD PRIMARY KEY (`db_name`,`table_name`);

--
-- Indizes für die Tabelle `pma__table_uiprefs`
--
ALTER TABLE `pma__table_uiprefs`
  ADD PRIMARY KEY (`username`,`db_name`,`table_name`);

--
-- Indizes für die Tabelle `pma__tracking`
--
ALTER TABLE `pma__tracking`
  ADD PRIMARY KEY (`db_name`,`table_name`,`version`);

--
-- Indizes für die Tabelle `pma__userconfig`
--
ALTER TABLE `pma__userconfig`
  ADD PRIMARY KEY (`username`);

--
-- Indizes für die Tabelle `pma__usergroups`
--
ALTER TABLE `pma__usergroups`
  ADD PRIMARY KEY (`usergroup`,`tab`,`allowed`);

--
-- Indizes für die Tabelle `pma__users`
--
ALTER TABLE `pma__users`
  ADD PRIMARY KEY (`username`,`usergroup`);

--
-- Indizes für die Tabelle `prt_queue`
--
ALTER TABLE `prt_queue`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `res`
--
ALTER TABLE `res`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `resdet1`
--
ALTER TABLE `resdet1`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `resnamen`
--
ALTER TABLE `resnamen`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `status`
--
ALTER TABLE `status`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `templates`
--
ALTER TABLE `templates`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `userdet`
--
ALTER TABLE `userdet`
  ADD PRIMARY KEY (`ID`);

--
-- Indizes für die Tabelle `zp_etage`
--
ALTER TABLE `zp_etage`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `zp_zimmer`
--
ALTER TABLE `zp_zimmer`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `Ages`
--
ALTER TABLE `Ages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `arr`
--
ALTER TABLE `arr`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-Res`
--
ALTER TABLE `AV-Res`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-Res-imp`
--
ALTER TABLE `AV-Res-imp`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-Res-n8n`
--
ALTER TABLE `AV-Res-n8n`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-Res-Save`
--
ALTER TABLE `AV-Res-Save`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-ResNamen`
--
ALTER TABLE `AV-ResNamen`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-ResNamen_Save`
--
ALTER TABLE `AV-ResNamen_Save`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV-ResNamen_Save1`
--
ALTER TABLE `AV-ResNamen_Save1`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV_local_Res`
--
ALTER TABLE `AV_local_Res`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `AV_ResDet`
--
ALTER TABLE `AV_ResDet`
  MODIFY `ID` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `av_res_audit`
--
ALTER TABLE `av_res_audit`
  MODIFY `audit_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `CartPrinters`
--
ALTER TABLE `CartPrinters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `countries`
--
ALTER TABLE `countries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `COVID`
--
ALTER TABLE `COVID`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `COVID_Tisch`
--
ALTER TABLE `COVID_Tisch`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `diet`
--
ALTER TABLE `diet`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `loginlog`
--
ALTER TABLE `loginlog`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `logis`
--
ALTER TABLE `logis`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `nachrichten`
--
ALTER TABLE `nachrichten`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `nomail`
--
ALTER TABLE `nomail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `origin`
--
ALTER TABLE `origin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__bookmark`
--
ALTER TABLE `pma__bookmark`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__column_info`
--
ALTER TABLE `pma__column_info`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__export_templates`
--
ALTER TABLE `pma__export_templates`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__history`
--
ALTER TABLE `pma__history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__pdf_pages`
--
ALTER TABLE `pma__pdf_pages`
  MODIFY `page_nr` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `pma__savedsearches`
--
ALTER TABLE `pma__savedsearches`
  MODIFY `id` int(5) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `prt_queue`
--
ALTER TABLE `prt_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `res`
--
ALTER TABLE `res`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `resdet1`
--
ALTER TABLE `resdet1`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `resnamen`
--
ALTER TABLE `resnamen`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `status`
--
ALTER TABLE `status`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `templates`
--
ALTER TABLE `templates`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `user`
--
ALTER TABLE `user`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `userdet`
--
ALTER TABLE `userdet`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `zp_etage`
--
ALTER TABLE `zp_etage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `zp_zimmer`
--
ALTER TABLE `zp_zimmer`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
