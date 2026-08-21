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
# Re-running is safe. Every stage checks for the state it produces rather than
# for a file it is about to create, so an interrupted run is repaired by
# running it again — including a half-written .env, which is the one failure
# that would otherwise leave the box looking provisioned and be unbootable.
#
# What re-running does NOT do: move the checkout to a newer commit. It fetches
# but never resets, because a hard reset on a box somebody may be mid-debug on
# is not a thing a provisioning script should do unasked. Deploys are the
# deploy workflow's job. Changing the hostname IS picked up — pass the new one.
#
# What it does not do at all, deliberately:
#
#   - Create the droplet, or touch DNS. Both need an account this script has no
#     business holding credentials for, and DNS must already resolve before
#     Caddy can complete an ACME challenge.
#   - Fill in the application secrets. It writes a .env with the infrastructure
#     settings correct and every secret blank — except APP_KEY, which it
#     generates, because it is the one secret with no external source and an
#     empty one means the application will not boot. A provisioning script that
#     invents a database password is a provisioning script that puts one in
#     your shell history. See docs/Environment and secrets.md.
#   - Deploy. That is .github/workflows/deploy-staging.yml, which takes over
#     once STAGING_ENABLED is set.
#
# Flags:
#   --print-key   re-print the deploy key and exit, for when the first run's
#                 output has scrolled away.

set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-deploy}"
DEPLOY_PATH="${DEPLOY_PATH:-/srv/brawling-mahogany}"
REPO_URL="${REPO_URL:-https://github.com/imonroe/brawling-mahogany.git}"
BRANCH="${BRANCH:-dev}"
SSH_DIR="/home/$DEPLOY_USER/.ssh"

# The marker the .env rewrite reaches last. Guarding on this rather than on the
# file's existence is what makes an interrupted run repairable: `cp` creates the
# file in its first instruction, so `[ -f .env ]` goes true long before the file
# is correct.
ENV_MARKER='APP_ENV=staging'

usage() {
    echo "Usage: $0 <hostname>       e.g. $0 staging.example.com" >&2
    echo "       $0 --print-key      re-print the deploy key" >&2
}

PRINT_KEY_ONLY=false
SERVER_NAME=''

case "${1:-}" in
    --print-key)
        PRINT_KEY_ONLY=true
        ;;
    -h|--help)
        usage
        exit 0
        ;;
    '')
        usage
        echo >&2
        echo "The hostname must already resolve to this droplet: Caddy requests a" >&2
        echo "certificate for it on first boot, and ACME's challenge arrives over" >&2
        echo "public DNS." >&2
        exit 1
        ;;
    -*)
        echo "Unknown option: $1" >&2
        usage
        exit 1
        ;;
    *)
        SERVER_NAME="$1"
        # Caught here rather than at first boot: the surrounding prose is full
        # of URLs, so pasting one in is the easy mistake, and it produces
        # APP_URL=https://https://… and a SERVER_NAME Caddy cannot use.
        case "$SERVER_NAME" in
            *://*|*/*|*' '*)
                echo "Give a bare hostname, not a URL: staging.example.com" >&2
                exit 1
                ;;
        esac
        ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root on the droplet." >&2
    exit 1
fi

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

print_key() {
    printf '\n\033[1m--- STAGING_SSH_KEY (private; paste into the secret, then clear your scrollback) ---\033[0m\n'
    cat "$SSH_DIR/id_ed25519"
    echo "--- end ---"
}

if [ "$PRINT_KEY_ONLY" = true ]; then
    [ -f "$SSH_DIR/id_ed25519" ] || { echo "No deploy key at $SSH_DIR/id_ed25519." >&2; exit 1; }
    print_key
    exit 0
fi

say "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
# python3 and openssl are used below. They are on every stock Ubuntu cloud
# image, but naming them means a stripped one fails here, loudly, rather than
# halfway through writing the .env.
apt-get install -y -qq ca-certificates curl git ufw python3 openssl >/dev/null

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
    # Fetch, never reset. Moving somebody's checkout out from under them is the
    # deploy workflow's decision to make, not this script's.
    sudo -u "$DEPLOY_USER" git -C "$DEPLOY_PATH" fetch origin "$BRANCH"
    echo "Checkout exists; fetched $BRANCH without moving the working tree."
fi

say "SSH key for the deploy user"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$SSH_DIR"
if [ ! -f "$SSH_DIR/id_ed25519" ]; then
    sudo -u "$DEPLOY_USER" ssh-keygen -t ed25519 -N '' -C "deploy@$SERVER_NAME" \
        -f "$SSH_DIR/id_ed25519" >/dev/null
    KEY_IS_NEW=true
else
    KEY_IS_NEW=false
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

if grep -q "^${ENV_MARKER}\$" "$ENV_FILE" 2>/dev/null; then
    echo "Already written; leaving $ENV_FILE alone."
else
    # 0600 from the moment it exists rather than after APP_KEY lands in it:
    # `cp` would create it 0644 under the default umask, and the key would sit
    # in a world-readable file for the width of the next two commands.
    install -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0600 \
        "$DEPLOY_PATH/.env.example" "$ENV_FILE"

    # APP_KEY first, so that if anything below fails the marker is absent and a
    # re-run rewrites the whole file rather than skipping it.
    KEY="base64:$(openssl rand -base64 32)"
    sudo -u "$DEPLOY_USER" python3 - "$ENV_FILE" "$SERVER_NAME" "$KEY" <<'PY'
import pathlib, re, sys

path, server_name, app_key = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
text = path.read_text()

settings = {
    "APP_KEY": app_key,
    "APP_DEBUG": "false",
    # Caddy terminates TLS itself once it is told a hostname (Deployment §3).
    "SERVER_NAME": server_name,
    "APP_URL": f"https://{server_name}",
    # ACME's HTTP and TLS-ALPN challenges arrive on 80 and 443 and nowhere else.
    "APP_PORT": "80",
    "APP_TLS_PORT": "443",
    # Migrations are a deploy step, never a container start-up side effect.
    "AUTO_MIGRATE": "false",
    # Without this a bare `docker compose ps|logs|up` on the droplet resolves
    # compose.local.yaml — which includes compose.yaml under the same project
    # name and overrides it with the development target, a bind mount over
    # /app, Mailpit and Vite. The deploy workflow passes -f explicitly, but a
    # person at 2am does not.
    "COMPOSE_FILE": "compose.yaml",
    # APP_ENV is written LAST: it is the marker the shell checks to decide
    # whether this file is finished. Anything that fails before here leaves it
    # absent, and the next run starts over.
    "APP_ENV": "staging",
}

for key, value in settings.items():
    pattern = re.compile(rf"^{re.escape(key)}=.*$", re.MULTILINE)
    # A lambda, not a replacement string: `\` and `\1` in a value would
    # otherwise be interpreted as backreferences.
    if pattern.search(text):
        text = pattern.sub(lambda _match: f"{key}={value}", text, count=1)
    else:
        text += f"\n{key}={value}\n"

path.write_text(text)
PY
    echo "Wrote $ENV_FILE (0600, secrets blank)."
fi

cat <<EOF

$(printf '\033[1m==> Provisioned.\033[0m')

The droplet has Docker, a firewall, a $DEPLOY_USER user, a checkout of
$BRANCH at $DEPLOY_PATH, and a .env with the infrastructure settings correct
and every application secret blank.

Three things left, none of which this script should do for you:

1. Fill in $ENV_FILE. It is already 0600 and owned by $DEPLOY_USER.
   docs/Environment and secrets.md §2 is the authority — that table, not this
   list, is what to work from. The ones it marks real on staging are:

     DB_PASSWORD              REDIS_PASSWORD
     MAIL_* (SES)             MAIL_REDIRECT_TO   <- set this before anything
     SENTRY_LARAVEL_DSN          can send; blank means NO redirection, and
     AWS_* (Spaces bucket)       every message goes to its real recipient
     VAPID_* (web push)
     HORIZON_AUTHORIZED_EMAILS
     the AI provider key and its budget cap

2. Add these repository secrets at
   https://github.com/imonroe/brawling-mahogany/settings/secrets/actions

     STAGING_SSH_HOST   $SERVER_NAME
     STAGING_SSH_USER   $DEPLOY_USER
     STAGING_PATH       $DEPLOY_PATH
     STAGING_URL        https://$SERVER_NAME
     STAGING_SSH_KEY    the private key (\`$0 --print-key\`)

3. Add these repository *variables* (variables, not secrets) at
   https://github.com/imonroe/brawling-mahogany/settings/variables/actions

     STAGING_ENABLED      true
     UPTIME_STAGING_URL   https://$SERVER_NAME

   The uptime check only starts ticking once the workflow reaches \`main\`,
   since GitHub runs schedules from the default branch only.

Then merge anything to \`dev\` and the deploy workflow takes over.
EOF

if [ "$KEY_IS_NEW" = true ]; then
    print_key
else
    echo
    echo "Deploy key already existed; not re-printing it. Use \`$0 --print-key\`."
fi
