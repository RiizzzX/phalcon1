#!/usr/bin/env python3
import paramiko

HOST = "192.168.0.73"
USER = "fdx"
PASSWORD = "k2Zd2qS2j"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD)

print("[*] Checking database tables...")
stdin, stdout, stderr = ssh.exec_command(
    "docker exec phalcon-inventory-mysql-1 mysql -uroot -psecret phalcon_db -e 'SHOW TABLES;'"
)
stdout.channel.recv_exit_status()
print(stdout.read().decode())

print("\n[*] Checking inventory table data...")
stdin, stdout, stderr = ssh.exec_command(
    "docker exec phalcon-inventory-mysql-1 mysql -uroot -psecret phalcon_db -e 'DESCRIBE inventory; SELECT * FROM inventory;'"
)
stdout.channel.recv_exit_status()
print(stdout.read().decode())

ssh.close()
