#!/usr/bin/env bash
#
# Provision a fresh Ubuntu droplet to run this stack (issue #36).
#
# Run it as root on a brand-new droplet, giving it the hostname DNS already
# points at:
#
#   scp scripts/provision-staging.sh root@<droplet-ip>:/tmp/
#   ssh root@<droplet-ip> 'bash /tmp/provision-staging.sh staging.example.com'
#
# It is idempotent: run it again after a failure, or to pick up a change, and
# it will skip what is already done rather than duplicating it.
#
# What it does NOT do, deliberately:
#
#   - Create the droplet, or touch DNS. Both need an account this script has no
#     business holding credentials for, and DNS must already resolve before
#     Caddy can complete an ACME challenge.
#   - Fill in the application secrets. It writes a .env with the infrastructure
#     settings correct and every secret left blank, because a provisioning
#     script that invents a database password is a provisioning script that
#     puts one in your shell history. See docs/Environment and secrets.md.
#   - Deploy. That is .github/workflows/deploy-staging.yml, which takes over
#     once STAGING_ENABLED is set.
#
# It prints the deploy key and the exact repository secrets to set at the end.

set -euo pipefail

SERVER_NAME="${1:-}"
DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/brawling-mahogany}"
REPO_URL="${REPO_URL:-https://github.com/imonroe/brawling-mahogany.git}"
BRANCH="${BRANCH:-dev}"

if [ -z "$SERVER_NAME" ]; then
    echo "Usage: $0 <hostname>    e.g. $0 staging.example.com" >&2
    echo >&2
    echo "The hostname must already resolve to this droplet: Caddy requests a" >&2
    echo "certificate for it on first boot, and ACME's challenge arrives over" >&2
    echo "public DNS." >&2
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root on the droplet." >&2
    exit 1
fi

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl git ufw >/dev/null

say "Docker"
if ! command -v docker >/dev/null; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin >/dev/null
fi
docker --version
docker compose version

say "Firewall"
# 22 for the deploy, 80 and 443 for Caddy — 80 is not optional, because ACME's
# HTTP challenge lands there and a redirect-only port 80 still has to exist.
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null   # HTTP/3
ufw --force enable >/dev/null
ufw status verbose | head -12

say "Deploy user"
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos '' "$DEPLOY_USER"
fi
usermod -aG docker "$DEPLOY_USER"

install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0755 "$(dirname "$DEPLOY_PATH")"

say "Checkout at $DEPLOY_PATH"
if [ ! -d "$DEPLOY_PATH/.git" ]; then
    sudo -u "$DEPLOY_USER" git clone --branch "$BRANCH" "$REPO_URL" "$DEPLOY_PATH"
else
    sudo -u "$DEPLOY_USER" git -C "$DEPLOY_PATH" fetch origin "$BRANCH"
    sudo -u "$DEPLOY_USER" git -C "$DEPLOY_PATH" checkout "$BRANCH"
fi

say "SSH key for the deploy user"
SSH_DIR="/home/$DEPLOY_USER/.ssh"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$SSH_DIR"
if [ ! -f "$SSH_DIR/id_ed25519" ]; then
    sudo -u "$DEPLOY_USER" ssh-keygen -t ed25519 -N '' -C "deploy@$SERVER_NAME" \
        -f "$SSH_DIR/id_ed25519" >/dev/null
fi
# The deploy workflow authenticates *to* this box with this key, so the public
# half goes in this user's own authorized_keys.
touch "$SSH_DIR/authorized_keys"
if ! grep -qFf "$SSH_DIR/id_ed25519.pub" "$SSH_DIR/authorized_keys" 2>/dev/null; then
    cat "$SSH_DIR/id_ed25519.pub" >> "$SSH_DIR/authorized_keys"
fi
chown "$DEPLOY_USER:$DEPLOY_USER" "$SSH_DIR/authorized_keys"
chmod 0600 "$SSH_DIR/authorized_keys"

say ".env"
ENV_FILE="$DEPLOY_PATH/.env"
if [ ! -f "$ENV_FILE" ]; then
    sudo -u "$DEPLOY_USER" cp "$DEPLOY_PATH/.env.example" "$ENV_FILE"

    # The infrastructure settings, which this script does know. Everything else
    # stays blank on purpose — see the header.
    sudo -u "$DEPLOY_USER" python3 - "$ENV_FILE" "$SERVER_NAME" <<'PY'
import pathlib, re, sys

path, server_name = pathlib.Path(sys.argv[1]), sys.argv[2]
text = path.read_text()

settings = {
    "APP_ENV": "staging",
    "APP_DEBUG": "false",
    # Caddy terminates TLS itself once it is told a hostname (Deployment §3).
    "SERVER_NAME": server_name,
    "APP_URL": f"https://{server_name}",
    # ACME's HTTP and TLS-ALPN challenges arrive on 80 and 443 and nowhere else.
    "APP_PORT": "80",
    "APP_TLS_PORT": "443",
    # Migrations are a deploy step, never a container start-up side effect.
    "AUTO_MIGRATE": "false",
    # Staging must never reach a real client (Environment and secrets).
    "MAIL_REDIRECT_TO": "",
}

for key, value in settings.items():
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.MULTILINE)
    if pattern.search(text):
        text = pattern.sub(f"{key}={value}", text)
    else:
        text += f"\n{key}={value}\n"

path.write_text(text)
PY

    # APP_KEY is generated rather than left blank: it is the one secret with no
    # external source, and an empty one means the app will not boot.
    KEY="base64:$(openssl rand -base64 32)"
    sudo -u "$DEPLOY_USER" sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" "$ENV_FILE"
fi
chmod 0600 "$ENV_FILE"
chown "$DEPLOY_USER:$DEPLOY_USER" "$ENV_FILE"

cat <<EOF

$(printf '\033[1m==> Provisioned.\033[0m')

The droplet has Docker, a firewall, a $DEPLOY_USER user, a checkout of
$BRANCH at $DEPLOY_PATH, and a .env with the infrastructure settings correct
and every application secret blank.

Three things left, none of which this script should do for you:

1. Fill in $ENV_FILE — DB_PASSWORD, MAIL_*, SENTRY_LARAVEL_DSN, the AI
   provider key and its budget cap. It is already 0600 and owned by
   $DEPLOY_USER. See docs/Environment and secrets.md.

   Set MAIL_REDIRECT_TO to your own address before anything can send.

2. Add these repository secrets at
   https://github.com/imonroe/brawling-mahogany/settings/secrets/actions

     STAGING_SSH_HOST   $SERVER_NAME
     STAGING_SSH_USER   $DEPLOY_USER
     STAGING_PATH       $DEPLOY_PATH
     STAGING_URL        https://$SERVER_NAME
     STAGING_SSH_KEY    the private key printed below

3. Add these repository *variables* (variables, not secrets) at
   https://github.com/imonroe/brawling-mahogany/settings/variables/actions

     STAGING_ENABLED      true
     UPTIME_STAGING_URL   https://$SERVER_NAME

   The uptime check only starts ticking once the workflow reaches \`main\`,
   since GitHub runs schedules from the default branch only.

Then merge anything to \`dev\` and the deploy workflow takes over.

$(printf '\033[1m--- STAGING_SSH_KEY (private, paste into the secret, then clear your scrollback) ---\033[0m')
EOF
cat "$SSH_DIR/id_ed25519"
echo "--- end ---"
