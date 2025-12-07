SET search_path TO tenant_eaque_duis_quasi_rep, public;

DELETE FROM radcheck WHERE username = 'xuxu';

INSERT INTO radcheck (username, attribute, op, value)
VALUES ('xuxu', 'Cleartext-Password', ':=', 'Pa$$w0rd!');

SELECT username, attribute, value FROM radcheck WHERE username = 'xuxu';
