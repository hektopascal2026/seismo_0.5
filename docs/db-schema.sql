-- =============================================================================
-- Seismo — consolidated base schema (schema version 17)
-- =============================================================================
--
-- Origin: flattened end state of Seismo 0.4's `initDatabase()` (SCHEMA_VERSION = 17).
-- All CREATE TABLE statements use IF NOT EXISTS — safe on empty databases and
-- no-ops on databases already populated by 0.4.
--
-- In Seismo 0.5 this file is applied by `php migrate.php` via
-- `Seismo\Migration\Migration001BaseSchema`. Do not execute manually unless you
-- know what you are doing.
--
-- Engine / charset: InnoDB, utf8mb4 / utf8mb4_unicode_ci (unless noted).
-- MySQL 5.7+ / MariaDB 10.3+ assumed (JSON columns, ENUM widening).
--
-- Entry-type model
-- ----------------
-- Five content tables act as "entries": feed_items, emails (or fetched_emails,
-- see below), lex_items, calendar_events, plus SRF srf_items (optional add-on).
-- Scoring/label/favourite tables reference them via (entry_type, entry_id)
-- where entry_type ∈ { feed_item, email, lex_item, calendar_event }.
-- NOTE: srf_items is not yet wired into the entry_type ENUMs.
--
-- Email table naming
-- ------------------
-- Historically the web app uses `emails`, but the CLI mail fetcher
-- (fetcher/mail/fetch_mail.php) writes to `fetched_emails` with a different
-- schema. `getEmailTableName()` in config.php resolves which one to read at
-- runtime. Both are included below; a fresh install should pick one.
--
-- Satellite mode
-- --------------
-- When SEISMO_MOTHERSHIP_DB is set, entry-source tables (feeds, feed_items,
-- lex_items, calendar_events, sender_tags, the email table) live in the
-- mothership DB and are read cross-DB. Scoring tables (entry_scores,
-- magnitu_config, magnitu_labels, entry_favourites) are always local.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- RSS / Substack / scraper feed sources
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS feeds (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    url                  VARCHAR(500) NOT NULL UNIQUE,
    source_type          VARCHAR(20)  DEFAULT 'rss',   -- 'rss' | 'substack' | 'scraper'
    title                VARCHAR(255) NOT NULL,
    description          TEXT,
    link                 VARCHAR(500),
    category             VARCHAR(100) DEFAULT NULL,
    disabled             TINYINT(1)   DEFAULT 0,
    consecutive_failures INT          NOT NULL DEFAULT 0,
    last_error           TEXT         DEFAULT NULL,
    last_error_at        DATETIME     DEFAULT NULL,
    last_fetched         DATETIME     DEFAULT NULL,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_url          (url),
    INDEX idx_category     (category),
    INDEX idx_disabled     (disabled),
    INDEX idx_source_type  (source_type),
    INDEX idx_last_fetched (last_fetched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Items parsed out of feeds (one row per article/post/scraped link)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS feed_items (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    feed_id        INT NOT NULL,
    guid           VARCHAR(500) NOT NULL,
    title          VARCHAR(500) NOT NULL,
    link           VARCHAR(500),
    description    TEXT,
    content        MEDIUMTEXT,                      -- widened from TEXT
    author         VARCHAR(255),
    published_date DATETIME,
    content_hash   VARCHAR(32) DEFAULT NULL,        -- scraper dedup
    hidden         TINYINT(1)  NOT NULL DEFAULT 0,  -- soft-delete
    cached_at      TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_feed_guid (feed_id, guid(255)),
    INDEX idx_feed_id   (feed_id),
    INDEX idx_guid      (guid(255)),
    INDEX idx_published (published_date),
    FOREIGN KEY (feed_id) REFERENCES feeds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Emails — web-app variant (used by Seismo’s email import / display path).
-- entry_type='email' in scoring tables refers to rows in this (or fetched_emails).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS emails (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    subject       VARCHAR(500) DEFAULT NULL,
    from_email    VARCHAR(255) DEFAULT NULL,
    from_name     VARCHAR(255) DEFAULT NULL,
    text_body     TEXT,
    html_body     TEXT,
    date_received DATETIME  DEFAULT NULL,
    date_sent     DATETIME  DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at    (created_at),
    INDEX idx_from_email    (from_email),
    INDEX idx_date_received (date_received)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Emails — CLI fetcher variant (fetcher/mail/fetch_mail.php).
-- Different column set; table name is configurable via magnitu_config.mail_db_table
-- (default 'fetched_emails'). Resolved at runtime by getEmailTableName().
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fetched_emails (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    imap_uid    BIGINT UNSIGNED NOT NULL,
    message_id  VARCHAR(255) NULL,
    from_addr   TEXT NULL,
    to_addr     TEXT NULL,
    cc_addr     TEXT NULL,
    subject     TEXT NULL,
    date_utc    DATETIME NULL,
    body_text   LONGTEXT NULL,
    body_html   LONGTEXT NULL,
    raw_headers LONGTEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_imap_uid (imap_uid),
    KEY idx_message_id (message_id(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------------
-- Per-sender tagging / quieting for email entries.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sender_tags (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    from_email VARCHAR(255) NOT NULL UNIQUE,
    tag        VARCHAR(100) DEFAULT NULL,
    disabled   TINYINT(1)   DEFAULT 0,
    removed_at DATETIME     DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_from_email (from_email),
    INDEX idx_tag        (tag),
    INDEX idx_disabled   (disabled),
    INDEX idx_removed_at (removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Newsletter / email subscription registry (domain-first, with per-address overrides).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_subscriptions (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    match_type            ENUM('domain','email') NOT NULL,
    match_value           VARCHAR(255) NOT NULL,
    display_name          VARCHAR(255) NOT NULL,
    category              VARCHAR(100) DEFAULT NULL,
    disabled              TINYINT(1)   NOT NULL DEFAULT 0,
    auto_detected         TINYINT(1)   NOT NULL DEFAULT 1,
    unsubscribe_url       VARCHAR(1000) DEFAULT NULL,
    unsubscribe_mailto    VARCHAR(500)  DEFAULT NULL,
    unsubscribe_one_click TINYINT(1)   NOT NULL DEFAULT 0,
    first_seen_at         DATETIME     DEFAULT NULL,
    last_seen_at          DATETIME     DEFAULT NULL,
    item_count            INT          NOT NULL DEFAULT 0,
    removed_at            DATETIME     DEFAULT NULL,
    created_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_match (match_type, match_value),
    INDEX idx_disabled   (disabled),
    INDEX idx_removed_at (removed_at),
    INDEX idx_category   (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Legislation + case law: EU-Lex, Fedlex (CH), BGer/BGE (CH JUS), Parl-MM, etc.
-- `source` values: 'eu', 'ch', 'ch_bger', 'ch_bge', ... (free-form VARCHAR(20))
-- `celex` is the natural key (reused for ELI IDs / SharePoint slugs → VARCHAR(255))
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lex_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    celex         VARCHAR(255) NOT NULL UNIQUE,
    title         TEXT,
    description   TEXT         DEFAULT NULL,
    document_date DATE         DEFAULT NULL,
    document_type VARCHAR(100) DEFAULT NULL,
    eurlex_url    VARCHAR(500) DEFAULT NULL,
    work_uri      VARCHAR(500) DEFAULT NULL,
    source        VARCHAR(20)  DEFAULT 'eu',
    fetched_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_celex         (celex),
    INDEX idx_document_date (document_date),
    INDEX idx_document_type (document_type),
    INDEX idx_source        (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- "Leg" — forward-looking parliamentary business (sessions, motions, publications).
-- Currently only source='parliament_ch' is implemented.
-- DELIBERATELY excluded from the Magnitu API (see .cursor/rules/calendar-events.mdc).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_events (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    source         VARCHAR(50)  NOT NULL,          -- e.g. 'parliament_ch'
    external_id    VARCHAR(255) DEFAULT NULL,
    title          TEXT,
    description    TEXT,
    content        TEXT,
    event_date     DATE         DEFAULT NULL,
    event_end_date DATE         DEFAULT NULL,
    event_type     VARCHAR(50)  DEFAULT NULL,
    status         VARCHAR(30)  DEFAULT 'scheduled',
    council        VARCHAR(10)  DEFAULT NULL,      -- 'N' | 'S' | 'V' (CH councils)
    url            VARCHAR(500) DEFAULT NULL,
    metadata       JSON         DEFAULT NULL,
    fetched_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_source_ext (source, external_id),
    INDEX idx_source     (source),
    INDEX idx_event_date (event_date),
    INDEX idx_event_type (event_type),
    INDEX idx_status     (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Magnitu / recipe-engine predictions. One row per scored entry.
-- score_source='recipe' (local deterministic scoring) or 'magnitu' (ML pushback).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entry_scores (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    entry_type      ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id        INT NOT NULL,
    relevance_score FLOAT       DEFAULT 0.0,
    predicted_label VARCHAR(50) DEFAULT NULL,     -- investigation_lead | important | background | noise
    explanation     JSON        DEFAULT NULL,     -- {top_features, confidence, prediction, ...}
    score_source    ENUM('magnitu','recipe') DEFAULT 'recipe',
    model_version   INT         DEFAULT 0,
    scored_at       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_entry (entry_type, entry_id),
    INDEX idx_entry_type_id   (entry_type, entry_id),
    INDEX idx_relevance       (relevance_score),
    INDEX idx_predicted_label (predicted_label),
    INDEX idx_score_source    (score_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- User-applied labels synced back from Magnitu for training.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS magnitu_labels (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id   INT NOT NULL,
    label      VARCHAR(50) NOT NULL,      -- investigation_lead | important | background | noise
    reasoning  TEXT DEFAULT NULL,
    labeled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_label (entry_type, entry_id),
    INDEX idx_entry (entry_type, entry_id),
    INDEX idx_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Star / bookmark state for timeline entries.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS entry_favourites (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id   INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favourite (entry_type, entry_id),
    INDEX idx_entry (entry_type, entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Generic key/value config — houses the Magnitu scoring recipe, API key,
-- IMAP mail fetcher settings, Lex/Jus circuit-breaker counters, and schema_version.
--
-- Notable keys:
--   api_key, alert_threshold, sort_by_relevance,
--   recipe_json, recipe_version, last_sync_at,
--   mail_imap_mailbox, mail_imap_username, mail_imap_password,
--   mail_max_messages, mail_search_criteria, mail_mark_seen, mail_db_table,
--   lex_<source>_failures (circuit breakers),
--   schema_version (mirrors SCHEMA_VERSION constant).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS magnitu_config (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    config_key   VARCHAR(100) NOT NULL UNIQUE,
    config_value MEDIUMTEXT   DEFAULT NULL,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- Web-page scraper targets (rows produce feed_items when scraped).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scraper_configs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) NOT NULL,
    url           VARCHAR(500) NOT NULL UNIQUE,
    link_pattern  VARCHAR(500) DEFAULT NULL,
    date_selector VARCHAR(500) DEFAULT NULL,
    category      VARCHAR(100) DEFAULT 'scraper',
    disabled      TINYINT(1)   DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_url      (url),
    INDEX idx_disabled (disabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- SRF monitor add-on (srf/sql/schema.sql) — separate pipeline; not in entry_type ENUM.
-- =============================================================================

CREATE TABLE IF NOT EXISTS srf_items (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    urn                  VARCHAR(512) NOT NULL,
    bu                   VARCHAR(16)  NOT NULL DEFAULT 'srf',
    episode_id           VARCHAR(128) NOT NULL,
    show_id              VARCHAR(128) DEFAULT NULL,
    show_title           VARCHAR(512) DEFAULT NULL,
    channel_id           VARCHAR(128) DEFAULT NULL,
    channel_title        VARCHAR(512) DEFAULT NULL,
    title                VARCHAR(512) DEFAULT NULL,
    description          TEXT,
    subtitle_text        LONGTEXT,
    subtitle_lang        VARCHAR(16)  DEFAULT NULL,
    permalink            VARCHAR(1024) DEFAULT NULL,
    published_at         DATETIME     DEFAULT NULL,
    subtitles_available  TINYINT(1)   NOT NULL DEFAULT 0,
    fetched_subtitles_at DATETIME     DEFAULT NULL,
    raw_metadata         JSON         DEFAULT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_srf_items_urn (urn),
    KEY idx_srf_items_published (published_at),
    KEY idx_srf_items_bu        (bu),
    KEY idx_srf_items_show      (show_id),
    KEY idx_srf_items_channel   (channel_id),
    FULLTEXT KEY ft_srf_items_search (title, description, subtitle_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS srf_fetch_state (
    state_key   VARCHAR(128) NOT NULL,
    state_value LONGTEXT,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- Review notes / known rough edges (pointers for the reviewer):
-- =============================================================================
--  1. Schema is applied procedurally in initDatabase() with try/catch around
--     every ALTER. There is no migration history table beyond
--     magnitu_config.schema_version (integer).
--  2. Two email tables coexist (`emails`, `fetched_emails`). Callers resolve
--     the live one via getEmailTableName(). A consolidation pass is desirable.
--  3. `entry_scores.entry_id` / `magnitu_labels.entry_id` / `entry_favourites.entry_id`
--     are soft references — no FKs, because entry_id points into one of four
--     different parent tables depending on entry_type. Orphan rows are possible
--     (e.g. after feed_items deletion cascades from feeds). Consider a cleanup job.
--  4. `srf_items` uses INT UNSIGNED for id while all other tables use INT
--     (signed) — minor inconsistency.
--  5. `calendar_event` is already in the entry_type ENUMs but is deliberately
--     excluded from Magnitu API endpoints (see .cursor/rules/calendar-events.mdc).
--  6. `lex_items.celex` is reused as a natural key for non-CELEX sources
--     (Fedlex ELI, SharePoint slugs). Column was widened to VARCHAR(255).
--  7. Several queries index `guid(255)` / `message_id(190)` because the full
--     VARCHAR(500)/VARCHAR(255) exceeds the 767/3072-byte key-length limits
--     under older row formats.
-- =============================================================================
