ALTER TABLE llx_lemonsuperpdp_event ADD INDEX idx_lemonsuperpdp_event_fk_transmission (fk_transmission);
ALTER TABLE llx_lemonsuperpdp_event ADD UNIQUE INDEX uk_lemonsuperpdp_event_superpdp_id (superpdp_event_id);
ALTER TABLE llx_lemonsuperpdp_event ADD INDEX idx_lemonsuperpdp_event_status (status_code);
ALTER TABLE llx_lemonsuperpdp_event ADD INDEX idx_lemonsuperpdp_event_entity (entity);
