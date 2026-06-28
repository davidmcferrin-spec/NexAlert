-- =============================================================================
-- Migration 002: Business unit org node types
-- global_business_unit — direct child of org root (corporate / NewsNation-wide)
-- business_unit         — direct child of a market node (local / station grouping)
-- =============================================================================

ALTER TABLE org_nodes
    MODIFY COLUMN node_type
        ENUM(
            'org',
            'global_business_unit',
            'region',
            'market',
            'business_unit',
            'site',
            'department',
            'team'
        ) NOT NULL;
