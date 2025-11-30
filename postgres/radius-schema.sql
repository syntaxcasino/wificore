-- FreeRADIUS PostgreSQL Schema

-- =========================
-- UUID EXTENSIONS
-- =========================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- =========================
-- NAS (Network Access Server) Table
-- =========================
CREATE TABLE IF NOT EXISTS nas (
    id serial PRIMARY KEY,
    nasname varchar(128) NOT NULL,
    shortname varchar(32),
    type varchar(30) DEFAULT 'other',
    ports integer,
    secret varchar(60) NOT NULL DEFAULT 'secret',
    server varchar(64),
    community varchar(50),
    description varchar(200) DEFAULT 'RADIUS Client'
);

CREATE INDEX IF NOT EXISTS nas_nasname ON nas(nasname);

-- =========================
-- RADIUS Authentication Tables
-- =========================
CREATE TABLE IF NOT EXISTS radcheck (
    id serial PRIMARY KEY,
    username varchar(64) NOT NULL DEFAULT '',
    attribute varchar(64) NOT NULL DEFAULT '',
    op char(2) NOT NULL DEFAULT '==',
    value varchar(253) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS radreply (
    id serial PRIMARY KEY,
    username varchar(64) NOT NULL DEFAULT '',
    attribute varchar(64) NOT NULL DEFAULT '',
    op char(2) NOT NULL DEFAULT '=',
    value varchar(253) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS radusergroup (
    id serial PRIMARY KEY,
    username varchar(64) NOT NULL DEFAULT '',
    groupname varchar(64) NOT NULL DEFAULT '',
    priority integer NOT NULL DEFAULT 1,
    created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS radgroupcheck (
    id serial PRIMARY KEY,
    groupname varchar(64) NOT NULL DEFAULT '',
    attribute varchar(64) NOT NULL DEFAULT '',
    op char(2) NOT NULL DEFAULT '==',
    value varchar(253) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS radgroupreply (
    id serial PRIMARY KEY,
    groupname varchar(64) NOT NULL DEFAULT '',
    attribute varchar(64) NOT NULL DEFAULT '',
    op char(2) NOT NULL DEFAULT '=',
    value varchar(253) NOT NULL DEFAULT '',
    created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS radacct (
    id bigserial PRIMARY KEY,
    acctsessionid varchar(64) NOT NULL DEFAULT '',
    acctuniqueid varchar(32) NOT NULL DEFAULT '',
    username varchar(64) NOT NULL DEFAULT '',
    groupname varchar(64) NOT NULL DEFAULT '',
    realm varchar(64) DEFAULT '',
    nasipaddress inet NOT NULL,
    nasportid varchar(15) DEFAULT NULL,
    nasporttype varchar(32) DEFAULT NULL,
    acctstarttime timestamp with time zone NULL DEFAULT NULL,
    acctupdatetime timestamp with time zone NULL DEFAULT NULL,
    acctstoptime timestamp with time zone NULL DEFAULT NULL,
    acctinterval int DEFAULT NULL,
    acctsessiontime int DEFAULT NULL,
    acctauthentic varchar(32) DEFAULT NULL,
    connectinfo_start varchar(50) DEFAULT NULL,
    connectinfo_stop varchar(50) DEFAULT NULL,
    acctinputoctets bigint DEFAULT NULL,
    acctoutputoctets bigint DEFAULT NULL,
    calledstationid varchar(50) NOT NULL DEFAULT '',
    callingstationid varchar(50) NOT NULL DEFAULT '',
    acctterminatecause varchar(32) NOT NULL DEFAULT '',
    servicetype varchar(32) DEFAULT NULL,
    framedprotocol varchar(32) DEFAULT NULL,
    framedipaddress inet DEFAULT NULL,
    framedipv6address inet DEFAULT NULL,
    framedipv6prefix inet DEFAULT NULL,
    framedinterfaceid varchar(64) DEFAULT NULL,
    delegatedipv6prefix inet DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS radacct_acctsessionid ON radacct(acctsessionid);
CREATE INDEX IF NOT EXISTS radacct_acctuniqueid ON radacct(acctuniqueid);
CREATE INDEX IF NOT EXISTS radacct_username ON radacct(username);
CREATE INDEX IF NOT EXISTS radacct_nasipaddress ON radacct(nasipaddress);
CREATE INDEX IF NOT EXISTS radacct_acctstarttime ON radacct(acctstarttime);

CREATE TABLE IF NOT EXISTS radpostauth (
    id bigserial PRIMARY KEY,
    username varchar(64) NOT NULL DEFAULT '',
    pass varchar(64) NOT NULL DEFAULT '',
    reply varchar(32) NOT NULL DEFAULT '',
    authdate timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    calledstationid varchar(50) DEFAULT NULL,
    callingstationid varchar(50) DEFAULT NULL
);

-- Insert sample user
INSERT INTO radcheck (username, attribute, op, value) 
VALUES ('testuser', 'Cleartext-Password', ':=', 'testpass')
ON CONFLICT DO NOTHING;