CREATE TABLE llx_lemonsuperpdp_ereporting (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  entity             integer NOT NULL DEFAULT 1,
  type               varchar(16) NOT NULL,
  fk_facture         integer NOT NULL,
  fk_paiement        integer NULL,
  superpdp_id        bigint NULL,
  status             varchar(16) NOT NULL DEFAULT 'pending',
  payload            mediumtext NULL,
  payload_response   mediumtext NULL,
  error_message      text NULL,
  date_creation      datetime NOT NULL,
  date_sent          datetime NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
