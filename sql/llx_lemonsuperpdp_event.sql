CREATE TABLE llx_lemonsuperpdp_event (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  fk_transmission    integer NOT NULL,
  fk_facture         integer NULL,
  entity             integer NOT NULL DEFAULT 1,
  superpdp_event_id  bigint NULL,
  status_code        varchar(16) NOT NULL,
  -- Motif du statut : code normalisé (MDT-113) + texte libre (MDT-114).
  -- La norme XP Z12-012 (BR-FR-CDV-15) exige un motif pour les statuts
  -- fr:206, fr:207, fr:208, fr:210, fr:213 et fr:501.
  reason_code        varchar(64) NULL,
  reason             varchar(255) NULL,
  message            varchar(255) NULL,
  direction          varchar(8) NOT NULL DEFAULT 'in',
  flux               varchar(20) NULL,
  seen               tinyint(1) NOT NULL DEFAULT 0,
  event_date         datetime NOT NULL,
  payload_raw        mediumtext NULL,
  date_creation      datetime NOT NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat      integer NULL
) ENGINE=innodb;
