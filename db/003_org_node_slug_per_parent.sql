-- =============================================================================
-- Migration 003: Scope org node slug uniqueness to parent
-- Allows multiple sites/departments/teams with the same slug under different
-- parents (e.g. "Engineering" dept under Site A and Site B).
-- Siblings under the same parent still require unique slugs.
-- =============================================================================

ALTER TABLE org_nodes
    DROP INDEX uq_org_slug,
    ADD UNIQUE KEY uq_org_parent_slug (org_id, parent_id, slug);
