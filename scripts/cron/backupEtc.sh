#!/bin/bash
# Backup /etc directory to an encrypted archive
# Archive name: backup-etc-YYYYMMDD-<random>.tar.gz.gpg
# Encryption passphrase is stored in /root/backup_etc_key to survive /etc loss

set -euo pipefail

# Wait up to two hours to obscure the exact backup time
if [ -z "${BACKUP_ETC_TEST:-}" ]; then
    sleep $(shuf -i0-7200 -n1)
fi

BACKUP_DIR_FILE="/root/.backup_dir"
KEY_FILE="/root/backup_etc_key"

if [ ! -f "$BACKUP_DIR_FILE" ]; then
    RAND_DIR=$(tr -dc A-Za-z0-9 </dev/urandom | head -c8 || true)
    BACKUP_DIR="/home/root-backup-${RAND_DIR}"
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    chown root:root "$BACKUP_DIR"
    echo "$BACKUP_DIR" > "$BACKUP_DIR_FILE"
    chmod 600 "$BACKUP_DIR_FILE"
else
    BACKUP_DIR=$(cat "$BACKUP_DIR_FILE")
    [ -d "$BACKUP_DIR" ] || {
        mkdir -p "$BACKUP_DIR"
        chmod 700 "$BACKUP_DIR"
        chown root:root "$BACKUP_DIR"
    }
fi

# Generate passphrase if it doesn't exist
if [ ! -f "$KEY_FILE" ]; then
    umask 177
    head -c 32 /dev/urandom | base64 | tr -d '\n' > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
fi

DATE="$(date +%Y%m%d)"
RAND=$(head -c 32 /dev/urandom | tr -dc A-Za-z0-9 | head -c 8 || true)
BACKUP_FILE="${BACKUP_DIR}/backup-etc-${DATE}-${RAND}.tar.gz.gpg"

# Use lowest priority for child processes
ionice -c3 -p $$ >/dev/null 2>&1 || true
renice +19 $$ >/dev/null 2>&1 || true

umask 177
tar -czf - /etc 2>/dev/null | \
    gpg --batch --yes --pinentry-mode loopback \
        --symmetric --cipher-algo AES256 --passphrase-file "$KEY_FILE" \
        -o "$BACKUP_FILE"

chown root:root "$BACKUP_FILE"
chmod 600 "$BACKUP_FILE"
