#!/usr/bin/env python3
import subprocess
import time

host = "192.168.0.73"
user = "fdx"

print("[*] Checking Docker Compose status on server...")
for i in range(12):  # Check 12 times (60 detik dengan interval 5 detik)
    result = subprocess.run(
        [f'ssh', f'{user}@{host}', 
         f'cd /home/fdx/dockerizer/phalcon-inventory && docker compose ps'],
        capture_output=True,
        text=True
    )
    print(f"\n[Check {i+1}/12]")
    print(result.stdout)
    if "Up" in result.stdout:
        print("[+] Containers are running!")
        break
    time.sleep(5)
    
print("\n[*] Final status:")
result = subprocess.run(
    [f'ssh', f'{user}@{host}', 
     f'cd /home/fdx/dockerizer/phalcon-inventory && docker compose ps && echo "---" && curl -s http://localhost:8080 | head -20'],
    capture_output=True,
    text=True
)
print(result.stdout)
