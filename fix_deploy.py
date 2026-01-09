#!/usr/bin/env python3
import paramiko

HOST = "192.168.0.73"
USER = "fdx"
PASSWORD = "k2Zd2qS2j"
DEPLOY_DIR = "/home/fdx/dockerizer/phalcon-inventory"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD)

# Check git status
print("[1] Git Status:")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && git log --oneline -3")
print(stdout.read().decode())

# Check docker-compose.yml
print("\n[2] Docker Compose Config:")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && cat docker-compose.yml | head -30")
print(stdout.read().decode())

# Check if containers exist
print("\n[3] All Containers (including stopped):")
stdin, stdout, stderr = ssh.exec_command(f"docker ps -a --format 'table {{.Names}}\t{{.Status}}'")
print(stdout.read().decode())

# Try to start docker compose
print("\n[4] Starting Docker Compose...")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose up -d 2>&1")
exit_status = stdout.channel.recv_exit_status()
output = stdout.read().decode()
errors = stderr.read().decode()
print(output)
if errors:
    print(f"ERRORS: {errors}")

# Wait a bit and check status
print("\n[5] Final Status (waiting 10 seconds for startup):")
import time
time.sleep(10)
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose ps")
print(stdout.read().decode())

ssh.close()
