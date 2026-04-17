CREATE TABLE llx_lemonsuperpdp_event (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  fk_transmission    integer NOT NULL,
  entity             integer NOT NULL DEFAULT 1,
  superpdp_event_id  bigint NULL,
  status_code        varchar(16) NOT NULL,
  message            varchar(255) NULL,
  direction          varchar(8) NOT NULL DEFAULT 'in',
  event_date         datetime NOT NULL,
  payload_raw        mediumtext NULL,
  date_creation      datetime NOT NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat      integer NULL
) ENGINE=innodb;
