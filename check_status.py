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

# Check Docker status
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose ps")
exit_status = stdout.channel.recv_exit_status()
ps_output = stdout.read().decode()

print("=" * 70)
print("DEPLOYMENT STATUS")
print("=" * 70)
print(ps_output)

# Check services
stdin, stdout, stderr = ssh.exec_command(f"cd {DEPLOY_DIR} && docker compose logs --tail 20")
exit_status = stdout.channel.recv_exit_status()
logs = stdout.read().decode()
print("\nLATEST LOGS:")
print("-" * 70)
print(logs[-1000:] if len(logs) > 1000 else logs)

# Check ports
stdin, stdout, stderr = ssh.exec_command("netstat -tlnp 2>/dev/null | grep -E '8080|8081|3307'")
exit_status = stdout.channel.recv_exit_status()
ports = stdout.read().decode()
print("\nPORT STATUS:")
print("-" * 70)
print(ports if ports else "Checking ports...")

print("\n" + "=" * 70)
print("SERVER URLS:")
print("=" * 70)
print(f"Application:  http://{HOST}:8080")
print(f"phpMyAdmin:   http://{HOST}:8081")
print(f"MySQL:        {HOST}:3307 (user: root, password: secret)")
print("=" * 70)

ssh.close()
