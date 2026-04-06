#!/bin/bash
set -e

echo "Waiting for db-master..."
until mariadb -h db-master -uroot -prootpass -e "SELECT 1" >/dev/null 2>&1; do
  sleep 3
done

echo "Configuring replication on slave..."

MASTER_STATUS=$(mariadb -h db-master -uroot -prootpass -e "SHOW MASTER STATUS\G")
MASTER_LOG_FILE=$(echo "$MASTER_STATUS" | awk '/File:/ {print $2}')
MASTER_LOG_POS=$(echo "$MASTER_STATUS" | awk '/Position:/ {print $2}')

mariadb -uroot -prootpass <<EOF
STOP SLAVE;
RESET SLAVE ALL;
CHANGE MASTER TO
  MASTER_HOST='db-master',
  MASTER_USER='repl',
  MASTER_PASSWORD='replpass',
  MASTER_LOG_FILE='${MASTER_LOG_FILE}',
  MASTER_LOG_POS=${MASTER_LOG_POS};
START SLAVE;
EOF

echo "Replication configured."