#!/usr/bin/env bash
# =============================================================================
# EcoRuta · Demo en AWS Academy Learner Lab: una EC2 con Docker Compose
#
#   caddy (HTTPS automático + panel web) → api (Laravel, Dockerfile de la raíz) → db (PostGIS)
#   URLs: https://panel.<ip-con-guiones>.sslip.io y https://api.<ip-con-guiones>.sslip.io
#
# Uso (desde cualquier carpeta):
#   docker/aws/ecoruta-aws.sh              todo: infra + desplegar (+ sembrar la primera vez)
#   docker/aws/ecoruta-aws.sh infra        llave SSH, grupo de seguridad, EC2 e IP elástica
#   docker/aws/ecoruta-aws.sh desplegar    sube el último commit del backend, compila y sube el panel, migra
#   docker/aws/ecoruta-aws.sh sembrar      DemoSeeder + 3 meses de historial (regenera el historial)
#   docker/aws/ecoruta-aws.sh estado | ssh | logs [servicio] | detener | iniciar | destruir
#
# Todo es idempotente: volver a ejecutarlo reutiliza lo que ya existe (se busca por la etiqueta Name).
#
# Credenciales: en el Learner Lab, Start Lab (punto verde) → AWS Details → AWS CLI → Show, y
# pegar el bloque en ~/.aws/credentials. Duran unas 4 h y cambian en cada sesión.
# Al terminar la sesión el lab detiene la EC2 y la enciende en la siguiente; la IP elástica
# mantiene las URLs y restart: unless-stopped vuelve a levantar los contenedores.
#
# Secretos: se generan una sola vez en .env.aws (raíz del repo, ignorado por git) y se copian
# al servidor como /opt/ecoruta/.env. Ahí quedan las claves de acceso de la demo (DEMO_*).
#
# Variables opcionales: TIPO_INSTANCIA (t3.medium) · DISCO_GB (30) · AWS_REGION (us-east-1)
#   SSH_CIDR (tu IP pública/32) · DOMINIO_BASE (sslip.io) · API_HOST · PANEL_HOST
#   DIR_FRONT (../logistica_camion_front_end) · DEMO_ADMIN_EMAIL · CON_PANEL=0 (no recompila el panel)
# =============================================================================
set -Eeuo pipefail
shopt -s inherit_errexit

PROYECTO="${PROYECTO:-ecoruta-demo}"
REGION="${AWS_REGION:-${AWS_DEFAULT_REGION:-us-east-1}}"
TIPO_INSTANCIA="${TIPO_INSTANCIA:-t3.medium}"
DISCO_GB="${DISCO_GB:-30}"
DOMINIO_BASE="${DOMINIO_BASE:-sslip.io}"
CON_PANEL="${CON_PANEL:-1}"

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIR_BACK="$(cd "$DIR_SCRIPT/../.." && pwd)"
DIR_FRONT="${DIR_FRONT:-$(cd "$DIR_BACK/.." && pwd)/logistica_camion_front_end}"
ENV_LOCAL="${ENV_LOCAL:-$DIR_BACK/.env.aws}"

LLAVE_SSH="${LLAVE_SSH:-$HOME/.ssh/ecoruta-aws}"
NOMBRE_LLAVE="${NOMBRE_LLAVE:-ecoruta-aws}"
KNOWN_HOSTS="$HOME/.ssh/ecoruta_known_hosts"
USUARIO_SSH=ubuntu
DIR_REMOTO=/opt/ecoruta
SSH_OPTS=(-i "$LLAVE_SSH" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new
    -o UserKnownHostsFile="$KNOWN_HOSTS" -o ConnectTimeout=10 -o ServerAliveInterval=30 -o LogLevel=ERROR)

IP=""
INSTANCIA=""

# ---------------------------------------------------------------- utilidades
log() { printf '\033[1;32m==>\033[0m %s\n' "$*" >&2; }
aviso() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*" >&2; }
fallar() {
    printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2
    exit 1
}

requiere() {
    local c
    for c in "$@"; do
        command -v "$c" >/dev/null || fallar "Falta el comando '$c'."
    done
}

aws_() { aws --region "$REGION" --output text "$@"; }

# Igual que aws_, pero convierte el "None" de --output text en vacío.
valor() {
    local v
    v=$(aws_ "$@")
    [[ "$v" == None ]] && v=""
    printf '%s' "$v"
}

credenciales_vigentes() { aws sts get-caller-identity --region "$REGION" >/dev/null 2>&1; }

verificar_credenciales() {
    requiere aws
    credenciales_vigentes || fallar "AWS rechaza las credenciales. En el Learner Lab: Start Lab (esperar el punto verde) \
→ AWS Details → AWS CLI → Show, y pegar el bloque completo en ~/.aws/credentials."
}

remoto() { ssh "${SSH_OPTS[@]}" "$USUARIO_SSH@$IP" "$@"; }

# Reemplaza o agrega CLAVE=valor en .env.aws.
poner() {
    local tmp
    tmp=$(mktemp)
    awk -v k="$1" -v v="$2" 'index($0, k "=") == 1 { print k "=" v; hecho = 1; next } { print } END { if (!hecho) print k "=" v }' \
        "$ENV_LOCAL" >"$tmp"
    cat "$tmp" >"$ENV_LOCAL"
    rm -f "$tmp"
}

# Clave aleatoria con mayúsculas, minúsculas, dígitos y símbolo; sin caracteres que rompan un .env.
clave() { printf 'Eco%s-%s' "$(openssl rand -hex 6)" "$(openssl rand -hex 3 | tr 'a-f' 'A-F')"; }

calcular_hosts() {
    local guiones=${IP//./-}
    API_HOST="${API_HOST:-api.$guiones.$DOMINIO_BASE}"
    PANEL_HOST="${PANEL_HOST:-panel.$guiones.$DOMINIO_BASE}"
}

# ---------------------------------------------------------------- infraestructura
asegurar_llave() {
    if [[ ! -f "$LLAVE_SSH" ]]; then
        log "Creando la llave SSH $LLAVE_SSH (exclusiva de esta demo)"
        mkdir -p "$(dirname "$LLAVE_SSH")"
        ssh-keygen -q -t ed25519 -N '' -C "$PROYECTO" -f "$LLAVE_SSH"
    fi
    if ! aws_ ec2 describe-key-pairs --key-names "$NOMBRE_LLAVE" >/dev/null 2>&1; then
        log "Registrando la llave pública en EC2 como $NOMBRE_LLAVE"
        aws_ ec2 import-key-pair --key-name "$NOMBRE_LLAVE" --public-key-material "fileb://$LLAVE_SSH.pub" >/dev/null
    fi
}

# Abre un puerto en el grupo; si la regla ya existe no hace nada.
autorizar() {
    local salida
    if ! salida=$(aws_ ec2 authorize-security-group-ingress --group-id "$1" \
        --ip-permissions "IpProtocol=$2,FromPort=$3,ToPort=$3,IpRanges=[{CidrIp=$4,Description=$5}]" 2>&1); then
        grep -q 'InvalidPermission.Duplicate' <<<"$salida" || fallar "$salida"
    fi
}

asegurar_grupo() {
    local vpc=$1 grupo cidr
    grupo=$(valor ec2 describe-security-groups \
        --filters "Name=vpc-id,Values=$vpc" "Name=group-name,Values=$PROYECTO-sg" --query 'SecurityGroups[0].GroupId')
    if [[ -z "$grupo" ]]; then
        log "Creando el grupo de seguridad $PROYECTO-sg"
        grupo=$(valor ec2 create-security-group --group-name "$PROYECTO-sg" --vpc-id "$vpc" \
            --description "EcoRuta demo: HTTP/HTTPS publicos, SSH solo desde la IP de despliegue" \
            --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=$PROYECTO-sg},{Key=Proyecto,Value=$PROYECTO}]" \
            --query GroupId)
    fi
    autorizar "$grupo" tcp 80 0.0.0.0/0 http
    autorizar "$grupo" tcp 443 0.0.0.0/0 https
    autorizar "$grupo" udp 443 0.0.0.0/0 http3
    cidr="${SSH_CIDR:-$(curl -fsS --max-time 10 https://checkip.amazonaws.com | tr -d '[:space:]')/32}"
    autorizar "$grupo" tcp 22 "$cidr" ssh-despliegue
    printf '%s' "$grupo"
}

# t3 no existe en todas las zonas (p. ej. us-east-1e): elige una subred por defecto donde sí.
elegir_subred() {
    local vpc=$1 zonas
    zonas=$(valor ec2 describe-instance-type-offerings --location-type availability-zone \
        --filters "Name=instance-type,Values=$TIPO_INSTANCIA" --query 'InstanceTypeOfferings[].Location')
    zonas=$(tr -s '[:space:]' ',' <<<"$zonas")
    zonas=${zonas%,}
    [[ -n "$zonas" ]] || fallar "$TIPO_INSTANCIA no está disponible en $REGION."
    valor ec2 describe-subnets \
        --filters "Name=vpc-id,Values=$vpc" "Name=default-for-az,Values=true" "Name=availability-zone,Values=$zonas" \
        --query 'sort_by(Subnets,&AvailabilityZone)[0].SubnetId'
}

buscar_instancia() {
    INSTANCIA=$(valor ec2 describe-instances \
        --filters "Name=tag:Name,Values=$PROYECTO" "Name=instance-state-name,Values=pending,running,stopping,stopped" \
        --query 'Reservations[].Instances[].InstanceId | [0]')
}

estado_instancia() { valor ec2 describe-instances --instance-ids "$INSTANCIA" --query 'Reservations[0].Instances[0].State.Name'; }

crear_instancia() {
    local grupo=$1 subred=$2 ami="" raiz
    ami=$(valor ssm get-parameter --query Parameter.Value \
        --name /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id 2>/dev/null) || ami=""
    if [[ -z "$ami" ]]; then
        ami=$(valor ec2 describe-images --owners 099720109477 --query 'sort_by(Images,&CreationDate)[-1].ImageId' \
            --filters 'Name=name,Values=ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*' Name=state,Values=available)
    fi
    [[ -n "$ami" ]] || fallar "No encontré la AMI de Ubuntu 24.04 en $REGION."
    raiz=$(valor ec2 describe-images --image-ids "$ami" --query 'Images[0].RootDeviceName')

    log "Creando la EC2 $PROYECTO ($TIPO_INSTANCIA, Ubuntu 24.04, $DISCO_GB GB)"
    local args=(ec2 run-instances --image-id "$ami" --instance-type "$TIPO_INSTANCIA" --count 1
        --key-name "$NOMBRE_LLAVE" --subnet-id "$subred" --security-group-ids "$grupo"
        --block-device-mappings "DeviceName=$raiz,Ebs={VolumeSize=$DISCO_GB,VolumeType=gp3,DeleteOnTermination=true,Encrypted=true}"
        --metadata-options HttpTokens=required,HttpEndpoint=enabled
        --user-data "file://$DIR_SCRIPT/user-data.sh"
        --tag-specifications
        "ResourceType=instance,Tags=[{Key=Name,Value=$PROYECTO},{Key=Proyecto,Value=$PROYECTO}]"
        "ResourceType=volume,Tags=[{Key=Name,Value=$PROYECTO},{Key=Proyecto,Value=$PROYECTO}]"
        --query 'Instances[0].InstanceId')
    # LabInstanceProfile permite abrir una terminal por Session Manager desde la consola si falla el SSH.
    INSTANCIA=$(aws_ "${args[@]}" --iam-instance-profile Name=LabInstanceProfile 2>/dev/null) ||
        INSTANCIA=$(aws_ "${args[@]}")
    log "EC2 creada: $INSTANCIA"
}

asegurar_ip() {
    local asignacion asociada
    asignacion=$(valor ec2 describe-addresses --filters "Name=tag:Name,Values=$PROYECTO" --query 'Addresses[0].AllocationId')
    if [[ -z "$asignacion" ]]; then
        log "Reservando una IP elástica"
        asignacion=$(valor ec2 allocate-address --domain vpc --query AllocationId \
            --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=$PROYECTO},{Key=Proyecto,Value=$PROYECTO}]")
    fi
    asociada=$(valor ec2 describe-addresses --allocation-ids "$asignacion" --query 'Addresses[0].InstanceId')
    if [[ "$asociada" != "$INSTANCIA" ]]; then
        aws_ ec2 associate-address --allocation-id "$asignacion" --instance-id "$INSTANCIA" --allow-reassociation >/dev/null
    fi
    IP=$(valor ec2 describe-addresses --allocation-ids "$asignacion" --query 'Addresses[0].PublicIp')
}

esperar_servidor() {
    local i
    log "Esperando SSH en $IP"
    for i in $(seq 1 60); do
        remoto true 2>/dev/null && break
        [[ $i -lt 60 ]] || fallar "La EC2 no responde por SSH (¿cambió tu IP pública? vuelve a ejecutar: $0 infra)."
        sleep 5
    done
    log "Esperando que cloud-init instale Docker (la primera vez tarda 2-4 min)"
    remoto 'sudo cloud-init status --wait >/dev/null 2>&1; test -f /var/lib/ecoruta-listo && sudo docker compose version >/dev/null' ||
        fallar "cloud-init no terminó bien. Revisa con: $0 ssh y luego: sudo cat /var/log/cloud-init-output.log"
}

infra() {
    verificar_credenciales
    requiere ssh ssh-keygen curl
    asegurar_llave

    local vpc grupo subred nueva=0
    vpc=$(valor ec2 describe-vpcs --filters Name=isDefault,Values=true --query 'Vpcs[0].VpcId')
    [[ -n "$vpc" ]] || fallar "La cuenta no tiene VPC por defecto en $REGION."
    grupo=$(asegurar_grupo "$vpc")

    buscar_instancia
    if [[ -z "$INSTANCIA" ]]; then
        subred=$(elegir_subred "$vpc")
        [[ -n "$subred" ]] || fallar "No hay subred por defecto con $TIPO_INSTANCIA disponible."
        crear_instancia "$grupo" "$subred"
        nueva=1
    else
        local estado
        estado=$(estado_instancia)
        log "La EC2 $INSTANCIA ya existe ($estado)"
        if [[ "$estado" == stopping ]]; then
            aws_ ec2 wait instance-stopped --instance-ids "$INSTANCIA"
            estado=stopped
        fi
        if [[ "$estado" == stopped ]]; then
            log "Encendiendo $INSTANCIA"
            aws_ ec2 start-instances --instance-ids "$INSTANCIA" >/dev/null
        fi
    fi
    aws_ ec2 wait instance-running --instance-ids "$INSTANCIA"
    asegurar_ip
    # Una EC2 nueva tras la misma IP elástica trae otra llave de host.
    if [[ $nueva == 1 ]]; then
        ssh-keygen -R "$IP" -f "$KNOWN_HOSTS" >/dev/null 2>&1 || true
    fi
    calcular_hosts
    preparar_env
    esperar_servidor
    log "EC2 lista: $INSTANCIA · $IP"
}

# Busca la IP por AWS; con las credenciales vencidas usa la que quedó en .env.aws.
cargar_ip() {
    if credenciales_vigentes; then
        IP=$(valor ec2 describe-addresses --filters "Name=tag:Name,Values=$PROYECTO" --query 'Addresses[0].PublicIp')
        buscar_instancia
    elif [[ -f "$ENV_LOCAL" ]]; then
        IP=$(sed -n 's/^EC2_IP=//p' "$ENV_LOCAL")
        [[ -z "$IP" ]] || aviso "Credenciales de AWS vencidas: uso la IP guardada en .env.aws ($IP)."
    fi
    [[ -n "$IP" ]] || fallar "No conozco la IP de $PROYECTO. Ejecuta primero: $0 infra"
}

# ---------------------------------------------------------------- despliegue
preparar_env() {
    requiere openssl awk
    if [[ ! -f "$ENV_LOCAL" ]]; then
        log "Generando secretos nuevos en $ENV_LOCAL (ignorado por git)"
        (umask 077 && cp "$DIR_BACK/.env.example" "$ENV_LOCAL")
        poner APP_KEY "base64:$(openssl rand -base64 32)"
        poner DB_PASSWORD "$(openssl rand -hex 24)"
        poner DEMO_ADMIN_EMAIL "${DEMO_ADMIN_EMAIL:-admin@ecoruta.cl}"
        poner DEMO_ADMIN_PASSWORD "$(clave)"
        poner DEMO_CHOFER_PASSWORD "$(clave)"
    fi
    chmod 600 "$ENV_LOCAL"

    # Valores del ambiente: se reescriben en cada despliegue (los secretos de arriba no).
    local k v
    while read -r k v; do
        poner "$k" "$v"
    done <<EOF
APP_ENV production
APP_DEBUG false
APP_DEMO_MODE true
APP_URL https://$API_HOST
FRONTEND_URL https://$PANEL_HOST
CORS_ALLOWED_ORIGINS https://$PANEL_HOST
LOG_CHANNEL stderr
LOG_LEVEL info
DB_CONNECTION pgsql
DB_HOST db
DB_PORT 5432
DB_DATABASE ecoruta
DB_USERNAME ecoruta
DB_SSLMODE disable
SESSION_DRIVER database
CACHE_STORE database
QUEUE_CONNECTION sync
MAIL_MAILER log
MAIL_FROM_NAME "EcoRuta Temuco"
OSRM_BASE_URL https://router.project-osrm.org
PHP_CLI_SERVER_WORKERS 4
API_HOST $API_HOST
PANEL_HOST $PANEL_HOST
EC2_IP $IP
EOF
}

subir_backend() {
    requiere git tar
    local commit
    commit=$(git -C "$DIR_BACK" rev-parse --short HEAD)
    if [[ -n "$(git -C "$DIR_BACK" status --porcelain --untracked-files=no)" ]]; then
        aviso "El backend tiene cambios sin commit: se despliega el último commit ($commit)."
    fi
    log "Subiendo el backend ($commit) y la configuración a $DIR_REMOTO"
    remoto "mkdir -p $DIR_REMOTO/panel && rm -rf $DIR_REMOTO/api.nuevo && mkdir -p $DIR_REMOTO/api.nuevo"
    git -C "$DIR_BACK" archive --format=tar HEAD |
        remoto "tar -x -C $DIR_REMOTO/api.nuevo && rm -rf $DIR_REMOTO/api && mv $DIR_REMOTO/api.nuevo $DIR_REMOTO/api"
    tar -C "$DIR_SCRIPT" -cf - docker-compose.yml Caddyfile | remoto "tar -x -C $DIR_REMOTO"
    remoto "umask 077 && cat > $DIR_REMOTO/.env" <"$ENV_LOCAL"
}

subir_panel() {
    if [[ "$CON_PANEL" != 1 ]]; then
        aviso "CON_PANEL=0: se mantiene el panel ya publicado."
        return
    fi
    requiere flutter
    [[ -d "$DIR_FRONT" ]] || fallar "No encuentro el frontend en $DIR_FRONT (define DIR_FRONT o usa CON_PANEL=0)."
    local salida="$DIR_FRONT/build/web_aws"
    if [[ -n "$(git -C "$DIR_FRONT" status --porcelain --untracked-files=no)" ]]; then
        aviso "El frontend tiene cambios sin commit: el panel se compila con ellos."
    fi
    log "Compilando el panel web contra https://$API_HOST"
    (cd "$DIR_FRONT" && flutter build web --release -o "$salida" \
        --dart-define=API_BASE_URL="https://$API_HOST" --dart-define=APP_DEMO_MODE=true)
    log "Subiendo el panel"
    remoto "mkdir -p $DIR_REMOTO/panel && find $DIR_REMOTO/panel -mindepth 1 -delete"
    tar -C "$salida" -cf - . | remoto "tar -x -C $DIR_REMOTO/panel"
}

levantar_servicios() {
    log "Construyendo la API y levantando los servicios (la primera vez tarda 5-10 min)"
    remoto "cd $DIR_REMOTO && sudo docker compose up -d --build --remove-orphans --wait --wait-timeout 600"
    log "Aplicando migraciones"
    remoto "cd $DIR_REMOTO && sudo docker compose exec -T api php artisan migrate --force"
    remoto "sudo docker image prune -f >/dev/null"
}

desplegar() {
    preparar_env
    subir_backend
    subir_panel
    levantar_servicios
}

sembrar() {
    log "Sembrando la demo (DemoSeeder + 3 meses de historial)"
    remoto "cd $DIR_REMOTO && sudo docker compose exec -T api php artisan db:seed --class=DemoSeeder --force \
        && sudo docker compose exec -T api php artisan ecoruta:generar-historial --meses=3 \
        && touch $DIR_REMOTO/.sembrado"
}

verificar() {
    local url i
    for url in "https://$API_HOST/up" "https://$PANEL_HOST/"; do
        for i in $(seq 1 24); do
            if curl -fsS -o /dev/null --max-time 10 "$url" 2>/dev/null; then
                log "OK  $url"
                continue 2
            fi
            sleep 5
        done
        aviso "Sin respuesta válida de $url. Revisa el certificado con: $0 logs caddy"
    done
}

resumen() {
    cat >&2 <<EOF

  Panel web : https://$PANEL_HOST
  API       : https://$API_HOST/api/v1   (salud: https://$API_HOST/up)
  Accesos   : DEMO_ADMIN_EMAIL, DEMO_ADMIN_PASSWORD y DEMO_CHOFER_PASSWORD en $ENV_LOCAL
  Servidor  : $0 ssh   ·   $0 logs api

  APK contra este servidor (en $DIR_FRONT):
    flutter build apk --release --target-platform android-arm64 --dart-define=API_BASE_URL=https://$API_HOST --dart-define=APP_DEMO_MODE=true

EOF
}

# ---------------------------------------------------------------- operación
estado() {
    cargar_ip
    calcular_hosts
    if [[ -n "$INSTANCIA" ]]; then
        log "EC2 $INSTANCIA: $(valor ec2 describe-instances --instance-ids "$INSTANCIA" \
            --query 'Reservations[0].Instances[0].[InstanceType,State.Name]') · IP $IP"
    fi
    local url codigo
    for url in "https://$API_HOST/up" "https://$PANEL_HOST/"; do
        codigo=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" || true)
        printf '  %-55s HTTP %s\n' "$url" "$codigo" >&2
    done
}

detener() {
    verificar_credenciales
    buscar_instancia
    [[ -n "$INSTANCIA" ]] || fallar "No hay una EC2 $PROYECTO."
    aws_ ec2 stop-instances --instance-ids "$INSTANCIA" >/dev/null
    log "Deteniendo $INSTANCIA. La IP elástica se conserva (cobra ~USD 0,005/h aunque la EC2 esté detenida)."
}

iniciar() {
    verificar_credenciales
    buscar_instancia
    [[ -n "$INSTANCIA" ]] || fallar "No hay una EC2 $PROYECTO. Créala con: $0"
    aws_ ec2 start-instances --instance-ids "$INSTANCIA" >/dev/null
    aws_ ec2 wait instance-running --instance-ids "$INSTANCIA"
    cargar_ip
    calcular_hosts
    verificar
    resumen
}

destruir() {
    verificar_credenciales
    local confirmacion asignacion grupo
    read -r -p "Se borrarán la EC2, su disco (con la base de datos), la IP elástica y el grupo de seguridad de $PROYECTO. Escribe '$PROYECTO' para confirmar: " confirmacion
    [[ "$confirmacion" == "$PROYECTO" ]] || fallar "Cancelado."
    buscar_instancia
    if [[ -n "$INSTANCIA" ]]; then
        log "Terminando $INSTANCIA"
        aws_ ec2 terminate-instances --instance-ids "$INSTANCIA" >/dev/null
        aws_ ec2 wait instance-terminated --instance-ids "$INSTANCIA"
    fi
    asignacion=$(valor ec2 describe-addresses --filters "Name=tag:Name,Values=$PROYECTO" --query 'Addresses[0].AllocationId')
    if [[ -n "$asignacion" ]]; then
        aws_ ec2 release-address --allocation-id "$asignacion"
        log "IP elástica liberada"
    fi
    grupo=$(valor ec2 describe-security-groups --filters "Name=group-name,Values=$PROYECTO-sg" --query 'SecurityGroups[0].GroupId')
    if [[ -n "$grupo" ]]; then
        aws_ ec2 delete-security-group --group-id "$grupo"
        log "Grupo de seguridad borrado"
    fi
    aviso "Se conservan $ENV_LOCAL y la llave $LLAVE_SSH (registrada en EC2 como $NOMBRE_LLAVE)."
}

# ---------------------------------------------------------------- principal
comando="${1:-todo}"
shift || true
case "$comando" in
todo)
    infra
    desplegar
    remoto "test -f $DIR_REMOTO/.sembrado" || sembrar
    verificar
    resumen
    ;;
infra)
    infra
    resumen
    ;;
desplegar)
    cargar_ip
    calcular_hosts
    desplegar
    verificar
    resumen
    ;;
sembrar)
    cargar_ip
    calcular_hosts
    sembrar
    ;;
estado) estado ;;
ssh)
    cargar_ip
    exec ssh "${SSH_OPTS[@]}" -t "$USUARIO_SSH@$IP" "cd $DIR_REMOTO 2>/dev/null; exec bash -l"
    ;;
logs)
    cargar_ip
    exec ssh "${SSH_OPTS[@]}" -t "$USUARIO_SSH@$IP" "cd $DIR_REMOTO && sudo docker compose logs -f --tail=200 ${1:-}"
    ;;
detener | iniciar | destruir) "$comando" ;;
-h | --help | ayuda) sed -n '3,/^# =====/p' "$0" | sed -e '$d' -e 's/^# \{0,1\}//' ;;
*) fallar "Comando desconocido: $comando (ver: $0 ayuda)" ;;
esac
