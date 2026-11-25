#!/bin/bash
# Primer arranque de la EC2 (cloud-init, una sola vez): swap, Docker y Docker Compose.
# Lo envía ecoruta-aws.sh al crear la instancia; no contiene secretos.
set -euxo pipefail
export DEBIAN_FRONTEND=noninteractive

# 2 GB de swap: la imagen de la API se compila en el servidor (Composer + extensiones PHP).
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Logs de contenedores acotados para no llenar el disco.
mkdir -p /etc/docker
cat > /etc/docker/daemon.json <<'JSON'
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "3" } }
JSON

apt-get update -q
apt-get install -y -q docker.io docker-compose-v2
systemctl enable --now docker
usermod -aG docker ubuntu

mkdir -p /opt/ecoruta
chown ubuntu:ubuntu /opt/ecoruta

touch /var/lib/ecoruta-listo
