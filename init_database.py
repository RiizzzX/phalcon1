#!/usr/bin/env python3
import paramiko
import time

HOST = "192.168.0.73"
USER = "fdx"
PASSWORD = "k2Zd2qS2j"
DEPLOY_DIR = "/home/fdx/dockerizer/phalcon-inventory"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD)

def exec_cmd(ssh, cmd, desc=""):
    print(f"\n[*] {desc}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_status = stdout.channel.recv_exit_status()
    out = stdout.read().decode()
    if out:
        print(out[:500])
    return out

# Pull latest changes
exec_cmd(ssh, f"cd {DEPLOY_DIR} && git pull origin master", "Pulling latest changes...")

# Stop containers
exec_cmd(ssh, f"cd {DEPLOY_DIR} && docker compose down", "Stopping containers...")

# Remove MySQL volume to reset database
exec_cmd(ssh, "docker volume rm phalcon-inventory_mysql_data 2>&1 || true", "Removing MySQL volume...")

# Rebuild and start
exec_cmd(ssh, f"cd {DEPLOY_DIR} && docker compose up -d --build", "Starting fresh containers with new schema...")

# Wait for MySQL to initialize
print("\n[*] Waiting for database to initialize (30 seconds)...")
time.sleep(30)

# Verify tables
exec_cmd(ssh, 
    "docker exec phalcon-inventory-mysql-1 mysql -uroot -psecret phalcon_db -e 'SHOW TABLES;'",
    "Checking database tables...")

exec_cmd(ssh,
    "docker exec phalcon-inventory-mysql-1 mysql -uroot -psecret phalcon_db -e 'SELECT * FROM inventory;'",
    "Checking inventory table data...")

# Final status
print("\n[+] Containers Status:")
exec_cmd(ssh, f"cd {DEPLOY_DIR} && docker compose ps", "")

print("\n✅ Database schema updated! Refresh http://192.168.0.73:8082")

ssh.close()
