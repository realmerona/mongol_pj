<?php
// Single source of truth for the data-spine schema (Phase 1):
// detection_reviews, species_prefs, notes. Additive only - the detections
// table is never altered here. The SQL copies in createdb.sh and
// update_birdnet_snippets.sh must stay in sync with this file.
// Standalone on purpose: no dependency on common.php so CLI tools
// (e.g. tests/seed_demo_db.php) can use it without config/session setup.

function spine_schema_statements_standalone() {
  return [
    "CREATE TABLE IF NOT EXISTS detection_reviews (
      id INTEGER PRIMARY KEY,
      file_name VARCHAR(100) NOT NULL UNIQUE,
      sci_name VARCHAR(100) NOT NULL,
      com_name VARCHAR(100) NOT NULL,
      date DATE NOT NULL,
      time TIME NOT NULL,
      status TEXT NOT NULL CHECK (status IN ('confirmed','false_positive','hidden','unsure')),
      reviewed_via TEXT,
      note TEXT,
      created_at TEXT DEFAULT (datetime('now','localtime')))",
    "CREATE INDEX IF NOT EXISTS idx_reviews_sci_status ON detection_reviews(sci_name, status)",
    "CREATE TABLE IF NOT EXISTS species_prefs (
      sci_name VARCHAR(100) PRIMARY KEY,
      com_name VARCHAR(100),
      favorite INTEGER NOT NULL DEFAULT 0,
      muted INTEGER NOT NULL DEFAULT 0,
      notify_mode TEXT NOT NULL DEFAULT 'default',
      custom_threshold FLOAT,
      crowned_clip VARCHAR(100),
      updated_at TEXT DEFAULT (datetime('now','localtime')))",
    "CREATE TABLE IF NOT EXISTS notes (
      id INTEGER PRIMARY KEY,
      date DATE,
      sci_name VARCHAR(100),
      file_name VARCHAR(100),
      body TEXT NOT NULL,
      created_at TEXT DEFAULT (datetime('now','localtime')))",
    "CREATE INDEX IF NOT EXISTS idx_notes_date ON notes(date)",
    "CREATE INDEX IF NOT EXISTS idx_notes_sci ON notes(sci_name)"
  ];
}
