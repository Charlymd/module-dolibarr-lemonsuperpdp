CREATE TABLE llx_lemonsuperpdp_transmission (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  fk_facture         integer NOT NULL,
  entity             integer NOT NULL DEFAULT 1,
  superpdp_id        bigint NULL,
  status             varchar(32) NOT NULL DEFAULT 'pending',
  status_raw         varchar(64) NULL,
  recipient_address  varchar(64) NULL,
  format_sent        varchar(16) NOT NULL DEFAULT 'facturx',
  error_message      text NULL,
  payload_response   mediumtext NULL,
  date_sent          datetime NOT NULL,
  date_status_update datetime NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_sent       integer NOT NULL
) ENGINE=innodb;
