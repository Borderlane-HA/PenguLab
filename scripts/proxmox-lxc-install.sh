#!/usr/bin/env bash
set -Eeuo pipefail

# PenguLab Proxmox VE LXC installer
# Creates an unprivileged Debian LXC, installs Docker and deploys PenguLab.
# Supports separate stable and prerelease image channels.

REPO_IMAGE="ghcr.io/borderlane-ha/pengulab"
DEFAULT_HOSTNAME="pengulab"
DEFAULT_BRIDGE="vmbr0"
DEFAULT_CORES="2"
DEFAULT_MEMORY="1024"
DEFAULT_SWAP="512"
DEFAULT_DISK="8"

if [[ -t 1 ]]; then
  BOLD='\033[1m'; BLUE='\033[34m'; GREEN='\033[32m'; YELLOW='\033[33m'; RED='\033[31m'; RESET='\033[0m'
else
  BOLD=''; BLUE=''; GREEN=''; YELLOW=''; RED=''; RESET=''
fi

info()  { printf "%b[INFO]%b %s\n" "$BLUE" "$RESET" "$*"; }
ok()    { printf "%b[ OK ]%b %s\n" "$GREEN" "$RESET" "$*"; }
warn()  { printf "%b[WARN]%b %s\n" "$YELLOW" "$RESET" "$*"; }
die()   { printf "%b[FAIL]%b %s\n" "$RED" "$RESET" "$*" >&2; exit 1; }

prompt() {
  local var_name="$1" text="$2" default="${3:-}" value
  if [[ -n "$default" ]]; then
    read -r -p "$text [$default]: " value
    value="${value:-$default}"
  else
    read -r -p "$text: " value
  fi
  printf -v "$var_name" '%s' "$value"
}

confirm() {
  local text="$1" default="${2:-N}" answer suffix
  if [[ "$default" =~ ^[Yy]$ ]]; then suffix="[Y/n]"; else suffix="[y/N]"; fi
  read -r -p "$text $suffix: " answer
  answer="${answer:-$default}"
  [[ "$answer" =~ ^[Yy]$ ]]
}

require_root() {
  [[ $EUID -eq 0 ]] || die "Run this script as root on the Proxmox VE host."
  command -v pct >/dev/null 2>&1 || die "pct was not found. This script must run on a Proxmox VE host."
  command -v pveversion >/dev/null 2>&1 || die "pveversion was not found."
}

validate_ctid() {
  [[ "$1" =~ ^[1-9][0-9]{2,8}$ ]] || die "Invalid CTID: $1"
  if pct status "$1" >/dev/null 2>&1; then
    die "CTID $1 already exists. Choose another CTID."
  fi
}

validate_hostname() {
  [[ "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || die "Invalid hostname: $1"
}

validate_tag() {
  [[ "$1" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || die "Invalid Docker image tag: $1"
}

first_storage_for_content() {
  local content="$1"
  pvesm status --content "$content" 2>/dev/null | awk 'NR>1 && $3=="active" {print $1; exit}'
}

select_template() {
  local storage="$1" template
  info "Refreshing Proxmox appliance template list..." >&2
  pveam update >/dev/null

  template="$(pveam available --section system 2>/dev/null | awk '$2 ~ /^debian-13-standard_.*_amd64\.tar\.(zst|gz)$/ {print $2}' | sort -V | tail -n1)"
  if [[ -z "$template" ]]; then
    warn "Debian 13 template not found; falling back to Debian 12." >&2
    template="$(pveam available --section system 2>/dev/null | awk '$2 ~ /^debian-12-standard_.*_amd64\.tar\.(zst|gz)$/ {print $2}' | sort -V | tail -n1)"
  fi
  [[ -n "$template" ]] || die "No supported Debian 12/13 LXC template found in pveam."

  if ! pveam list "$storage" 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fqx "$storage:vztmpl/$template"; then
    info "Downloading $template to $storage..." >&2
    # select_template is used inside command substitution. Keep pveam's
    # progress output out of stdout, otherwise it becomes part of the
    # ostemplate value passed to `pct create`.
    pveam download "$storage" "$template" >&2
  else
    info "Using cached template $template." >&2
  fi

  printf '%s:vztmpl/%s' "$storage" "$template"
}

wait_for_container() {
  local ctid="$1" tries=60
  info "Waiting for container startup and network..."
  while (( tries > 0 )); do
    if pct exec "$ctid" -- bash -lc 'ip -4 -o addr show dev eth0 | grep -q " inet "' >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
    ((tries--))
  done
  die "Container did not obtain an IPv4 address in time. Check bridge/VLAN/DHCP settings."
}

install_runtime() {
  local ctid="$1"
  info "Installing Docker inside the LXC..."
  pct exec "$ctid" -- bash -lc '
    set -e
    export DEBIAN_FRONTEND=noninteractive
    export LANG=C.UTF-8 LC_ALL=C.UTF-8
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl docker.io
    if apt-cache show docker-cli >/dev/null 2>&1; then
      apt-get install -y --no-install-recommends docker-cli
    fi

    if apt-cache show docker-compose-v2 >/dev/null 2>&1; then
      apt-get install -y --no-install-recommends docker-compose-v2
    elif apt-cache show docker-compose-plugin >/dev/null 2>&1; then
      apt-get install -y --no-install-recommends docker-compose-plugin
    elif apt-cache show docker-compose >/dev/null 2>&1; then
      apt-get install -y --no-install-recommends docker-compose
    else
      echo "No Docker Compose package found." >&2
      exit 1
    fi

    systemctl enable --now docker
    command -v docker >/dev/null 2>&1 || { echo "Docker CLI is missing after installation." >&2; exit 1; }
    mkdir -p /opt/pengulab/data /opt/pengulab/backups
  '
}

configure_console_autologin() {
  local ctid="$1"
  info "Enabling Proxmox web-console auto-login (local console only)..."
  pct exec "$ctid" -- bash -lc '
    set -e
    mkdir -p /etc/systemd/system/console-getty.service.d
    cat > /etc/systemd/system/console-getty.service.d/autologin.conf <<"EOF"
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear --keep-baud 115200,57600,38400,9600 - $TERM
EOF
    mkdir -p /etc/systemd/system/container-getty@1.service.d
    cat > /etc/systemd/system/container-getty@1.service.d/autologin.conf <<"EOF"
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear --keep-baud 115200,57600,38400,9600 %I $TERM
EOF
    cat > /etc/profile.d/pengulab-console.sh <<"EOF"
if [ -t 1 ] && [ "$(id -u)" = "0" ]; then
  printf "\nPenguLab LXC\n  pengulabctl status   Show status\n  pengulabctl update   Update current channel\n  pengulabctl logs     Follow logs\n\n"
fi
EOF
    chmod 0644 /etc/profile.d/pengulab-console.sh
    # PenguLab management is intentionally local through Proxmox Console/pct.
    # Do not expose an SSH login from this dedicated appliance container.
    systemctl disable --now ssh.service sshd.service 2>/dev/null || true
    systemctl daemon-reload
    systemctl restart console-getty.service 2>/dev/null || true
    systemctl restart container-getty@1.service 2>/dev/null || true
  '
}

install_pengulabctl() {
  local ctid="$1" tmp
  tmp="$(mktemp)"
  cat > "$tmp" <<'CTL'
#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR="/opt/pengulab"
ENV_FILE="$BASE_DIR/.env"
COMPOSE_FILE="$BASE_DIR/compose.yml"
BACKUP_DIR="$BASE_DIR/backups"
IMAGE_DEFAULT="ghcr.io/borderlane-ha/pengulab"

compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" "$@"
  else
    echo "Docker Compose is not installed." >&2
    exit 1
  fi
}

ensure_files() {
  mkdir -p "$BASE_DIR/data" "$BACKUP_DIR"
  [[ -f "$ENV_FILE" ]] || cat > "$ENV_FILE" <<EOFENV
PENGULAB_IMAGE=$IMAGE_DEFAULT
PENGULAB_TAG=latest
EOFENV

  [[ -f "$COMPOSE_FILE" ]] || cat > "$COMPOSE_FILE" <<'EOFCOMPOSE'
services:
  pengulab:
    image: ${PENGULAB_IMAGE}:${PENGULAB_TAG}
    container_name: pengulab
    restart: unless-stopped
    ports:
      - "19961:8080"
    volumes:
      - ./data:/app/data
EOFCOMPOSE
}

read_env_value() {
  local key="$1"
  grep -E "^${key}=" "$ENV_FILE" | tail -n1 | cut -d= -f2-
}

set_env_value() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  awk -v k="$key" -v v="$value" '
    BEGIN { done=0 }
    $0 ~ "^" k "=" { print k "=" v; done=1; next }
    { print }
    END { if (!done) print k "=" v }
  ' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
}

backup() {
  ensure_files
  local stamp target
  stamp="$(date +%Y%m%d-%H%M%S)"
  target="${1:-$BACKUP_DIR/pengulab-$stamp.tar.gz}"
  tar -C "$BASE_DIR" -czf "$target" data .env compose.yml
  echo "Backup: $target"
  find "$BACKUP_DIR" -maxdepth 1 -type f -name 'pengulab-*.tar.gz' -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr | awk 'NR>10 {print $2}' | xargs -r rm -f
}

update() {
  ensure_files
  local do_backup="${1:-yes}"
  if [[ "$do_backup" != "no-backup" ]] && [[ -n "$(find "$BASE_DIR/data" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
    backup >/dev/null
    echo "Automatic backup created."
  fi
  echo "Pulling $(read_env_value PENGULAB_IMAGE):$(read_env_value PENGULAB_TAG)..."
  compose pull
  compose up -d --remove-orphans
  compose ps
}

channel() {
  local value="${1:-}"
  case "$value" in
    stable)
      set_env_value PENGULAB_TAG latest
      echo "Channel set to stable (latest)."
      ;;
    prerelease|pre-release|preview)
      set_env_value PENGULAB_TAG prerelease
      echo "Channel set to prerelease."
      ;;
    *)
      echo "Usage: pengulabctl channel stable|prerelease" >&2
      exit 2
      ;;
  esac
}

version() {
  local tag="${1:-}"
  [[ "$tag" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || { echo "Invalid image tag." >&2; exit 2; }
  set_env_value PENGULAB_TAG "$tag"
  echo "Pinned PenguLab to image tag: $tag"
}

info() {
  ensure_files
  echo "Image:   $(read_env_value PENGULAB_IMAGE):$(read_env_value PENGULAB_TAG)"
  echo "Data:    $BASE_DIR/data"
  echo "Backups: $BACKUP_DIR"
  compose ps
}

usage() {
  cat <<'EOFHELP'
PenguLab LXC management

Usage:
  pengulabctl status
  pengulabctl update
  pengulabctl channel stable
  pengulabctl channel prerelease
  pengulabctl version 2.4.0
  pengulabctl backup [target.tar.gz]
  pengulabctl logs
  pengulabctl restart

Recommended for prerelease testing:
  Use a separate Proxmox LXC, then keep it on the prerelease channel.
EOFHELP
}

ensure_files
case "${1:-}" in
  status|info) info ;;
  update) update ;;
  channel) shift; channel "${1:-}" ;;
  version) shift; version "${1:-}" ;;
  backup) shift; backup "${1:-}" ;;
  logs) compose logs -f --tail=200 ;;
  restart) compose restart ;;
  ""|-h|--help|help) usage ;;
  *) usage; exit 2 ;;
esac
CTL
  chmod +x "$tmp"
  pct push "$ctid" "$tmp" /usr/local/bin/pengulabctl --perms 0755
  # `pct enter` on current Debian/Proxmox combinations can start root with a
  # minimal PATH that omits /usr/local/bin. Keep the canonical copy under
  # /usr/local/bin, but expose stable compatibility links in both sbin paths.
  pct exec "$ctid" -- ln -sfn /usr/local/bin/pengulabctl /usr/local/sbin/pengulabctl
  pct exec "$ctid" -- ln -sfn /usr/local/bin/pengulabctl /usr/bin/pengulabctl
  rm -f "$tmp"
}

configure_pengulab() {
  local ctid="$1" tag="$2"
  info "Configuring PenguLab image tag: $tag"
  pct exec "$ctid" -- bash -lc "mkdir -p /opt/pengulab/data /opt/pengulab/backups; cat > /opt/pengulab/.env <<ENVEOF
PENGULAB_IMAGE=$REPO_IMAGE
PENGULAB_TAG=$tag
ENVEOF
cat > /opt/pengulab/compose.yml <<'COMPOSEEOF'
services:
  pengulab:
    image: \${PENGULAB_IMAGE}:\${PENGULAB_TAG}
    container_name: pengulab
    restart: unless-stopped
    ports:
      - \"19961:8080\"
    volumes:
      - ./data:/app/data
COMPOSEEOF
cd /opt/pengulab && /usr/bin/pengulabctl update"
}

container_ip() {
  local ctid="$1"
  pct exec "$ctid" -- bash -lc "ip -4 -o addr show dev eth0 | awk '{print \\$4}' | cut -d/ -f1 | head -n1" 2>/dev/null || true
}

main() {
  require_root

  local pve major
  pve="$(pveversion 2>/dev/null | head -n1)"
  major="$(printf '%s' "$pve" | sed -nE 's/.*pve-manager\/([0-9]+).*/\1/p')"
  info "Detected $pve"
  if [[ -n "$major" && "$major" != "8" && "$major" != "9" ]]; then
    warn "This installer is tested for Proxmox VE 8/9. Detected major version ${major}."
    confirm "Continue anyway?" N || exit 0
  fi

  printf "\n%bPenguLab installation channel%b\n" "$BOLD" "$RESET"
  echo "  1) Stable      - production channel (Docker tag: latest)"
  echo "  2) Pre-release - alpha/beta testing (Docker tag: prerelease)"
  echo "  3) Exact tag   - pin a specific release"
  local choice tag channel_label
  prompt choice "Select" "1"
  case "$choice" in
    1) tag="latest"; channel_label="Stable" ;;
    2) tag="prerelease"; channel_label="Pre-release" ;;
    3)
      prompt tag "Exact Docker release tag (e.g. 2.4.0)" "2.4.0"
      validate_tag "$tag"
      channel_label="Pinned: $tag"
      ;;
    *) die "Invalid channel selection." ;;
  esac

  local nextid ctid hostname bridge vlan_tag root_storage template_storage net_mode net0 ip_cidr gateway
  nextid="$(pvesh get /cluster/nextid 2>/dev/null || echo 250)"
  prompt ctid "Container ID" "$nextid"
  validate_ctid "$ctid"

  prompt hostname "Hostname" "$DEFAULT_HOSTNAME"
  validate_hostname "$hostname"

  root_storage="$(first_storage_for_content rootdir)"
  [[ -n "$root_storage" ]] || die "No active Proxmox storage supporting rootdir was found."
  prompt root_storage "Root filesystem storage" "$root_storage"
  pvesm status --content rootdir 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fqx "$root_storage" || die "Storage '$root_storage' does not support LXC root filesystems."

  template_storage="$(first_storage_for_content vztmpl)"
  [[ -n "$template_storage" ]] || die "No active Proxmox storage supporting container templates was found."
  prompt template_storage "Template storage" "$template_storage"
  pvesm status --content vztmpl 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fqx "$template_storage" || die "Storage '$template_storage' does not support LXC templates."

  prompt bridge "Network bridge" "$DEFAULT_BRIDGE"
  ip link show "$bridge" >/dev/null 2>&1 || warn "Bridge '$bridge' was not found as a local Linux interface. Verify the name before continuing."
  prompt vlan_tag "VLAN tag (blank = untagged)" ""
  if [[ -n "$vlan_tag" ]]; then
    [[ "$vlan_tag" =~ ^[0-9]{1,4}$ ]] && (( vlan_tag >= 1 && vlan_tag <= 4094 )) || die "Invalid VLAN tag: $vlan_tag"
  fi

  echo ""
  echo "Network configuration:"
  echo "  1) DHCP (recommended for a quick test; reserve the address in DHCP afterwards)"
  echo "  2) Static IPv4"
  prompt net_mode "Select" "1"
  case "$net_mode" in
    1) net0="name=eth0,bridge=$bridge,ip=dhcp,type=veth" ;;
    2)
      prompt ip_cidr "IPv4/CIDR (e.g. 10.10.1.50/24)" ""
      [[ "$ip_cidr" =~ ^[0-9.]+/[0-9]{1,2}$ ]] || die "Invalid IPv4/CIDR format."
      prompt gateway "IPv4 gateway" ""
      [[ "$gateway" =~ ^[0-9.]+$ ]] || die "Invalid IPv4 gateway format."
      net0="name=eth0,bridge=$bridge,ip=$ip_cidr,gw=$gateway,type=veth"
      ;;
    *) die "Invalid network selection." ;;
  esac
  if [[ -n "$vlan_tag" ]]; then
    net0="$net0,tag=$vlan_tag"
  fi

  local cores="$DEFAULT_CORES" memory="$DEFAULT_MEMORY" swap="$DEFAULT_SWAP" disk="$DEFAULT_DISK"
  if confirm "Change CPU/RAM/disk defaults?" N; then
    prompt cores "CPU cores" "$cores"
    prompt memory "RAM in MiB" "$memory"
    prompt swap "Swap in MiB" "$swap"
    prompt disk "Disk in GiB" "$disk"
    [[ "$cores" =~ ^[1-9][0-9]*$ && "$memory" =~ ^[1-9][0-9]*$ && "$swap" =~ ^[0-9]+$ && "$disk" =~ ^[1-9][0-9]*$ ]] || die "Invalid resource value."
  fi

  printf "\n%bSummary%b\n" "$BOLD" "$RESET"
  printf "  CTID:       %s\n" "$ctid"
  printf "  Hostname:   %s\n" "$hostname"
  printf "  Channel:    %s\n" "$channel_label"
  printf "  Image tag:  %s\n" "$tag"
  printf "  Storage:    %s (%s GiB)\n" "$root_storage" "$disk"
  printf "  Network:    %s\n" "$net0"
  printf "  Resources:  %s core(s), %s MiB RAM\n" "$cores" "$memory"
  echo ""
  confirm "Create the LXC and install PenguLab?" Y || exit 0

  local template_ref
  template_ref="$(select_template "$template_storage")"

  info "Creating unprivileged LXC $ctid..."
  pct create "$ctid" "$template_ref" \
    --hostname "$hostname" \
    --cores "$cores" \
    --memory "$memory" \
    --swap "$swap" \
    --rootfs "$root_storage:$disk" \
    --net0 "$net0" \
    --unprivileged 1 \
    --features nesting=1,keyctl=1 \
    --timezone host \
    --tags pengulab \
    --description "PenguLab Homelab Control Center ($channel_label)" \
    --onboot 1 \
    --start 1

  wait_for_container "$ctid"
  install_runtime "$ctid"
  configure_console_autologin "$ctid"
  install_pengulabctl "$ctid"
  configure_pengulab "$ctid" "$tag"

  local ip
  ip="$(container_ip "$ctid")"
  echo ""
  ok "PenguLab installation completed."
  printf "  Proxmox CT: %s\n" "$ctid"
  printf "  Channel:    %s\n" "$channel_label"
  if [[ -n "$ip" ]]; then
    printf "  URL:        http://%s:19961\n" "$ip"
  else
    printf "  URL:        http://<LXC-IP>:19961\n"
  fi
  echo ""
  echo "Management commands inside the LXC:"
  echo "  Proxmox Web Console: automatic local root login (SSH service is disabled by this script)"
  echo "  pct enter $ctid"
  echo "  pengulabctl status"
  echo "  pengulabctl update"
  echo "  pengulabctl logs"
  echo ""
  if [[ "$tag" == "prerelease" || "$tag" == *alpha* || "$tag" == *beta* || "$tag" == *rc* ]]; then
    warn "This container uses a pre-release build. Keep it separate from your stable PenguLab instance and test with copied/temporary data."
  fi
}

main "$@"
