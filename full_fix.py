#!/usr/bin/env python3
import paramiko

HOST = "192.168.0.73"
USER = "fdx"
PASSWORD = "k2Zd2qS2j"
DEPLOY_DIR = "/home/fdx/dockerizer/phalcon-inventory"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD)

print("[*] Stopping all containers...")
stdin, stdout, stderr = ssh.exec_command("docker compose ps -a -q 2>/dev/null | xargs -r docker stop 2>&1")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Removing all containers...")
stdin, stdout, stderr = ssh.exec_command("docker compose ps -a -q 2>/dev/null | xargs -r docker rm 2>&1")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Checking what's using port 8080...")
stdin, stdout, stderr = ssh.exec_command("lsof -i :8080 2>/dev/null || netstat -tlnp 2>/dev/null | grep 8080")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Killing process on port 8080...")
stdin, stdout, stderr = ssh.exec_command("sudo lsof -ti :8080 | xargs -r sudo kill -9 2>/dev/null || echo 'No process found or permission issue'")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Pulling latest changes from git...")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && git fetch && git reset --hard origin/master")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Checking docker-compose.yml...")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && grep -A 3 'ports:' docker-compose.yml")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Starting fresh containers...")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose up -d --build 2>&1")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

import time
time.sleep(15)

print("\n[*] Final Status:")
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose ps")
stdout.channel.recv_exit_status()
print(stdout.read().decode())

ssh.close()
