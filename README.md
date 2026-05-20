# Midtbyen AD Sync

Automated Active Directory synchronization platform for Linux servers using LDAPS, Ansible and systemd.

## Components

- Active Directory (Windows Server)
- Python sync script (ldap3)
- systemd timer/service
- Ansible deployment
- Apache/PHP web dashboard
- SSHFS shared storage
- UFW firewall configuration

## Project Structure

```text
playbooks/     # Ansible playbooks
files/         # Service files, templates and sync.py
web/           # PHP dashboard
inventory/     # Ansible inventory
```

## Main playbooks
```text
01-create-ansible-user.yml
02-setup-ad-sync.yml
03-setup-ad-sync-systemd.yml
04-setup-fileserver.yml
05-setup-ufw.yml
06-deploy-web.yml
07-setup-websync.yml
```

## Deployment
```bash
Run playbooks in order:
ansible-playbook -i inventory/hosts.ini playbooks/01-create-ansible-user.yml --ask-become-pass
ansible-playbook -i inventory/hosts.ini playbooks/02-setup-ad-sync.yml
ansible-playbook -i inventory/hosts.ini playbooks/03-setup-ad-sync-systemd.yml
ansible-playbook -i inventory/hosts.ini playbooks/04-setup-fileserver.yml
ansible-playbook -i inventory/hosts.ini playbooks/05-setup-ufw.yml
ansible-playbook -i inventory/hosts.ini playbooks/06-deploy-web.yml
ansible-playbook -i inventory/hosts.ini playbooks/07-setup-websync.yml
```
