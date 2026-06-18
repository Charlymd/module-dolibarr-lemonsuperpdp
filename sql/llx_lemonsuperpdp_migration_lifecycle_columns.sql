-- =============================================================================
-- Migration : ajout colonnes cycle de vie dans llx_lemonsuperpdp_event
-- Exécutée automatiquement par _migrate_lifecycle_columns() dans modLemonSuperPDP.
-- Conservé ici comme référence / fallback manuel.
-- =============================================================================

ALTER TABLE llx_lemonsuperpdp_event
    ADD COLUMN IF NOT EXISTS flux VARCHAR(20)  DEFAULT NULL
        COMMENT 'fournisseur | pdp | client',
    ADD COLUMN IF NOT EXISTS seen TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '0 = non vu, 1 = vu par l''utilisateur';

-- Index pour les requêtes du widget (dernier event par flux via fk_transmission)
ALTER TABLE llx_lemonsuperpdp_event
    ADD INDEX IF NOT EXISTS idx_lsp_event_flux (fk_transmission, flux),
    ADD INDEX IF NOT EXISTS idx_lsp_event_code (fk_transmission, status_code);

-- Rétro-alimentation du champ flux sur les événements existants
UPDATE llx_lemonsuperpdp_event
    SET flux = 'fournisseur'
    WHERE status_code IN ('fr:200','fr:201','fr:202','fr:203','fr:204','fr:205')
      AND (flux IS NULL OR flux = '');

UPDATE llx_lemonsuperpdp_event
    SET flux = 'pdp'
    WHERE status_code IN ('ACK','ACK-01','ACK-02','REJECT','ROUTE')
      AND (flux IS NULL OR flux = '');

UPDATE llx_lemonsuperpdp_event
    SET flux = 'client'
    WHERE status_code IN ('fr:206','fr:207','fr:208','fr:209','fr:210','fr:211','fr:212')
      AND (flux IS NULL OR flux = '');
