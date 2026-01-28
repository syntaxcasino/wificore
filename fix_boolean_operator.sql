-- Fix PostgreSQL 18 strict type checking for boolean columns
-- Create function for boolean = integer comparison
CREATE OR REPLACE FUNCTION pg_catalog.bool_eq_int(boolean, integer) 
RETURNS boolean AS $$
BEGIN
    RETURN $1 = CASE 
        WHEN $2 = 0 THEN false
        WHEN $2 = 1 THEN true
        ELSE NULL
    END;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Create operator to allow boolean = integer
CREATE OPERATOR pg_catalog.= (
    LEFTARG = boolean,
    RIGHTARG = integer,
    FUNCTION = pg_catalog.bool_eq_int,
    COMMUTATOR = =
);

-- Create function for integer = boolean comparison
CREATE OR REPLACE FUNCTION pg_catalog.int_eq_bool(integer, boolean) 
RETURNS boolean AS $$
BEGIN
    RETURN CASE 
        WHEN $1 = 0 THEN false
        WHEN $1 = 1 THEN true
        ELSE NULL
    END = $2;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- Create operator to allow integer = boolean
CREATE OPERATOR pg_catalog.= (
    LEFTARG = integer,
    RIGHTARG = boolean,
    FUNCTION = pg_catalog.int_eq_bool
);
