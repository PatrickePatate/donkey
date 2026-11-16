SELECT 'CREATE DATABASE donkey_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'donkey_testing')\gexec

\c donkey
CREATE EXTENSION IF NOT EXISTS vector;
\c donkey_testing
CREATE EXTENSION IF NOT EXISTS vector;
