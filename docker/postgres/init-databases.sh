#!/bin/sh
set -eu

TEST_DB="${POSTGRES_TEST_DB:-titkul_canteen_test}"

if [ -n "$TEST_DB" ] && [ "$TEST_DB" != "${POSTGRES_DB:-}" ]; then
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
        SELECT 'CREATE DATABASE "${TEST_DB}" OWNER "${POSTGRES_USER}"'
        WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec
EOSQL
fi
