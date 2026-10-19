#!/bin/sh
set -eu
# Official MySQL image runs this as part of first-time initialization.
case "$MYSQL_USER" in ''|*[!a-zA-Z0-9_]*) echo 'Use an alphanumeric MYSQL_USER' >&2; exit 1;; esac
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS blindtyper_test CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
GRANT ALL ON blindtyper_test.* TO '$MYSQL_USER'@'%';
SQL
