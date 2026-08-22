#!/bin/bash
# SecureLab - MongoDB Backup Script
# Runs daily via cron to backup MongoDB database

set -e

# Configuration
MONGO_URI="${MONGODB_URI:-mongodb://localhost:27017/invisia}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mongodb}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="invisia_backup_${DATE}"

# Create backup directory
mkdir -p "${BACKUP_DIR}"

echo "[$(date)] Starting MongoDB backup..."

# Extract database name from URI
DB_NAME=$(echo "$MONGO_URI" | sed -E 's/.*\/([^?]+).*/\1/')
if [ -z "$DB_NAME" ]; then
    DB_NAME="invisia"
fi

# Run mongodump
mongodump --uri="${MONGO_URI}" --db="${DB_NAME}" --out="${BACKUP_DIR}/${BACKUP_NAME}" --gzip

if [ $? -eq 0 ]; then
    echo "[$(date)] Backup completed: ${BACKUP_DIR}/${BACKUP_NAME}"

    # Create tar.gz archive
    cd "${BACKUP_DIR}"
    tar -czf "${BACKUP_NAME}.tar.gz" "${BACKUP_NAME}"
    rm -rf "${BACKUP_NAME}"

    # Calculate size
    SIZE=$(du -h "${BACKUP_NAME}.tar.gz" | cut -f1)
    echo "[$(date)] Archive created: ${BACKUP_NAME}.tar.gz (${SIZE})"

    # Cleanup old backups
    find "${BACKUP_DIR}" -name "invisia_backup_*.tar.gz" -mtime +${RETENTION_DAYS} -delete
    echo "[$(date)] Old backups cleaned up (retention: ${RETENTION_DAYS} days)"

    # Log success
    echo "[$(date)] BACKUP SUCCESS: ${BACKUP_NAME}.tar.gz" >> "${BACKUP_DIR}/backup.log"
else
    echo "[$(date)] BACKUP FAILED" >> "${BACKUP_DIR}/backup.log"
    exit 1
fi