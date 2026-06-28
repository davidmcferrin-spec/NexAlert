-- =============================================================================
-- Migration 007: Compound AND terms on alert_targets (multi-tag/node/group AND)
-- When conj_terms JSON is set, resolution uses the full term list instead of
-- single target_* columns alone.
-- =============================================================================

ALTER TABLE alert_targets
    ADD COLUMN conj_terms JSON NULL COMMENT 'Full AND term list [{dim,value}] for compound rows'
    AFTER target_label;
