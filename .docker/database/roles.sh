#!/bin/sh
# Creates the two application roles (idempotent). Runs once on a fresh volume from /docker-entrypoint-initdb.d,
# and again on demand with `make db-roles` for an existing volume.
#   - depverify_owner: owns the database and its schemas, runs migrations and audit partition maintenance (CLI only);
#   - depverify_app: runtime role of the web workers, no DDL, INSERT/SELECT only on the audit log.
# POSTGRES_USER (superuser of the image) is only used here, never by the application.
set -eu

: "${POSTGRES_USER:?}"
: "${POSTGRES_DB:?}"
APP_PASSWORD="${DEPVERIFY_APP_PASSWORD:-!ChangeMeApp!}"
OWNER_PASSWORD="${DEPVERIFY_OWNER_PASSWORD:-!ChangeMeOwner!}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v app_password="$APP_PASSWORD" -v owner_password="$OWNER_PASSWORD" -v db="$POSTGRES_DB" <<'SQL'
SELECT 'CREATE ROLE depverify_owner LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'depverify_owner') \gexec
SELECT 'CREATE ROLE depverify_app LOGIN' WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'depverify_app') \gexec
ALTER ROLE depverify_owner WITH LOGIN NOSUPERUSER CREATEDB NOCREATEROLE PASSWORD :'owner_password';
ALTER ROLE depverify_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'app_password';
-- The owner becomes the database owner, hence of its public schema (pg_database_owner, PostgreSQL 15+).
ALTER DATABASE :"db" OWNER TO depverify_owner;
SQL
