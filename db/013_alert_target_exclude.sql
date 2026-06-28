-- Migration 013: Global target exclusions (EXCEPT / NOT syntax)
-- Run after 012_target_presets.sql

ALTER TABLE alerts
    ADD COLUMN target_exclude_terms JSON NULL COMMENT 'Excluded terms [{dim,value}] subtracted from resolved recipients'
    AFTER external_ref;
