#!/usr/bin/env python3
import paramiko
import sys
import time

# Server configuration
HOST = "192.168.0.73"
USER = "fdx"
PASSWORD = "k2Zd2qS2j"
DEPLOY_DIR = "/home/fdx/dockerizer/phalcon-inventory"
REPO_URL = "https://github.com/RiizzzX/phalcon1.git"

def execute_ssh_command(ssh, command, wait_time=2):
    """Execute a command via SSH and return output"""
    print(f"[DEBUG] Executing: {command}")
    stdin, stdout, stderr = ssh.exec_command(command)
    
    # Wait for command to complete and read output
    exit_code = stdout.channel.recv_exit_status()
    
    out = stdout.read().decode()
    err = stderr.read().decode()
    
    if out:
        print(f"[OUTPUT]\n{out}")
    if err and exit_code != 0:
        print(f"[ERROR]\n{err}")
    
    return out, err

def main():
    try:
        # Create SSH client
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        
        print("[*] Connecting to server...")
        ssh.connect(HOST, username=USER, password=PASSWORD, port=22)
        print("[+] Connected to server!")
        
        # Create deployment directory
        print(f"\n[*] Creating deployment directory: {DEPLOY_DIR}")
        execute_ssh_command(ssh, f"mkdir -p {DEPLOY_DIR}")
        
        # Clone repository
        print(f"\n[*] Cloning repository from {REPO_URL}")
        execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && git clone {REPO_URL} . 2>&1 || git pull origin master")
        
        # Verify clone
        print(f"\n[*] Verifying repository...")
        execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && ls -la")
        
        # Check if Docker/docker-compose is available
        print(f"\n[*] Checking Docker availability...")
        out, err = execute_ssh_command(ssh, f"which docker && which docker-compose || which docker && docker compose version")
        
        # Try docker-compose first, then docker compose
        docker_cmd = "docker-compose"
        out_compose, _ = execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && {docker_cmd} --version 2>&1")
        if "not found" in out_compose or "command not found" in out_compose:
            docker_cmd = "docker compose"
            print(f"[*] Using 'docker compose' instead of 'docker-compose'")
        
        # Build and start Docker containers
        print(f"\n[*] Starting Docker with: {docker_cmd}")
        execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && {docker_cmd} up -d --build")
        
        # Wait for containers to start
        print(f"\n[*] Waiting for containers to start...")
        time.sleep(5)
        
        # Check Docker status
        print(f"\n[*] Checking Docker status...")
        out, err = execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && {docker_cmd} ps")
        
        # Get port information
        print(f"\n[*] Checking service URLs...")
        out, err = execute_ssh_command(ssh, f"cd {DEPLOY_DIR} && {docker_cmd} config | grep -E 'ports:|image:' | head -15")
        
        # Final status
        print(f"\n[+] Deployment completed!")
        print(f"\n[+] Server Information:")
        print(f"    - Server IP: {HOST}")
        print(f"    - Deploy Directory: {DEPLOY_DIR}")
        print(f"    - Check status: ssh fdx@{HOST} 'cd {DEPLOY_DIR} && docker-compose ps'")
        
        ssh.close()
        
    except paramiko.AuthenticationException as e:
        print(f"[-] Authentication failed: {e}")
        sys.exit(1)
    except paramiko.SSHException as e:
        print(f"[-] SSH error: {e}")
        sys.exit(1)
    except Exception as e:
        print(f"[-] Error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
