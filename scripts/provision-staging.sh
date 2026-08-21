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
# Re-running is safe, and it is specific about what it will and will not touch:
#
#   - The .env gains one delimited block at the end, holding the infrastructure
#     settings. Re-running replaces that block and NOTHING else — Dotenv reads
#     the last definition of a key, so the block wins without the script having
#     to find, parse, or edit anything the operator wrote.
#   - Nothing outside the block is ever read for meaning or rewritten, so no
#     secret can be lost to a missed match. A DB_PASSWORD that Postgres is
#     already using cannot be recovered, which is why this is worth the
#     slightly odd-looking file.
#   - APP_KEY is generated once. If the operator has set one anywhere outside
#     the block it is left alone; otherwise the value from the previous block
#     is carried forward. It is never rotated — doing so would invalidate every
#     session and every encrypted column on the box.
#   - An interrupted run is repaired by running it again: the .env is written
#     through a temporary file and moved into place, so it is never left
#     half-written.
#   - The checkout is fetched but NEVER reset. A hard reset on a box somebody
#     may be mid-debug on is not a provisioning script's decision to make;
#     deploys are the deploy workflow's job.
#
# What it does not do at all, deliberately:
#
#   - Create the droplet, or touch DNS. Both need an account this script has no
#     business holding credentials for, and DNS must already resolve before
#     Caddy can complete an ACME challenge.
#   - Fill in the application secrets. APP_KEY is the exception, above: it is
#     the one secret with no external source, and an empty one means the
#     application will not boot. A provisioning script that invents a database
#     password is a provisioning script that puts one in your shell history.
#     See docs/Environment and secrets.md.
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
# python3 is used below. It is on every stock Ubuntu cloud image, but naming it
# means a stripped one fails here, loudly, rather than halfway through the .env.
apt-get install -y -qq ca-certificates curl git ufw python3 >/dev/null

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

# The .env stage appends rather than edits, and that is the whole design.
#
# An earlier version rewrote each key in place with `^KEY=.*$`. That regex is
# not Dotenv's grammar — it does not match `export KEY=x`, `KEY = x`, or an
# indented line, all of which Dotenv honours — and Dotenv resolves a key to its
# LAST definition, so any spelling the regex missed silently won. Five review
# rounds found five ways to lose that game.
#
# So the script stops parsing. It owns one delimited block at the end of the
# file and rewrites only that. Last definition wins, so the block's values win
# whatever the rest of the file says or how it spells it — and because nothing
# outside the block is ever touched, no secret can be destroyed by a bad match.
sudo -u "$DEPLOY_USER" python3 - "$ENV_FILE" "$DEPLOY_PATH/.env.example" "$SERVER_NAME" <<'PY'
import base64, os, pathlib, re, secrets, sys, tempfile

path, example, server_name = (pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]),
                              sys.argv[3])

OPEN = "# >>> provision-staging.sh — managed block; re-run the script to change it"
CLOSE = "# <<< provision-staging.sh — end of managed block"

created = not path.exists()

if created:
    text = example.read_text()
else:
    text = path.read_text()

# Whatever we wrote last time, so the block can be replaced rather than stacked.
previous = ""
block = re.search(rf"^{re.escape(OPEN)}$.*?^{re.escape(CLOSE)}$\n?", text,
                  re.MULTILINE | re.DOTALL)

if block:
    previous = block.group(0)
    text = text[:block.start()] + text[block.end():]

body = text.rstrip("\n")

# APP_KEY, in three cases, none of which may rotate a key already in use:
#
#   the operator defines one outside our block  -> leave it alone entirely
#   our previous block had one                  -> reuse that exact value
#   neither                                     -> generate one
#
def is_set(raw):
    """Would Dotenv read a non-empty value from this right-hand side?

    Only ever asked about APP_KEY, and only to decide whether to leave the
    operator's line alone. Quoted-empty counts as empty: `APP_KEY=""` looks
    set to a naive test, and treating it as set means generating no key and
    shipping a box that cannot boot.
    """
    value = raw.strip()

    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        return value[1:-1] != ""

    # An unquoted value ends at an inline comment — and a value that is
    # nothing but a comment is empty, which `APP_KEY= # rotate me` is.
    if value.startswith("#"):
        return False

    return value.split(" #")[0].strip() != ""


# Every spelling Dotenv honours — `export`, spaces around `=`, indentation —
# and the LAST one, because that is the one it would resolve to.
operator_values = re.findall(
    r"^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=(.*)$", body, re.MULTILINE)
operator_key = bool(operator_values) and is_set(operator_values[-1])

app_key = None

if not operator_key:
    reused = re.search(r"^APP_KEY=(\S+)$", previous, re.MULTILINE)
    app_key = reused.group(1) if reused else (
        "base64:" + base64.b64encode(secrets.token_bytes(32)).decode())

settings = [
    ("APP_ENV", "staging"),
    ("APP_DEBUG", "false"),
    # Caddy terminates TLS itself once it is told a hostname (Deployment §3).
    ("SERVER_NAME", server_name),
    ("APP_URL", f"https://{server_name}"),
    # ACME's HTTP and TLS-ALPN challenges arrive on 80 and 443, nowhere else.
    ("APP_PORT", "80"),
    ("APP_TLS_PORT", "443"),
    # Migrations are a deploy step, never a container start-up side effect.
    ("AUTO_MIGRATE", "false"),
    # Without this a bare `docker compose ps|logs|up` on the droplet resolves
    # compose.local.yaml — which includes compose.yaml under the same project
    # name and overrides it with the development target, a bind mount over
    # /app, Mailpit and Vite. The deploy workflow passes -f explicitly, but a
    # person at 2am does not.
    ("COMPOSE_FILE", "compose.yaml"),
]

if app_key:
    settings.insert(1, ("APP_KEY", app_key))

lines = [OPEN,
         "# Anything above this block is yours; nothing here is. Dotenv reads the",
         "# last definition of a key, so these win — edit them by editing this",
         "# block, or they will be restored on the next run."]
lines += [f"{key}={value}" for key, value in settings]
lines.append(CLOSE)

rendered = "\n".join(lines) + "\n"
updated = body + "\n\n" + rendered

# Through a temporary file in the same directory, so the .env is never seen
# half-written: a short write on a full disk would otherwise truncate it.
handle, temporary = tempfile.mkstemp(dir=path.parent, prefix=".env.")
try:
    with os.fdopen(handle, "w") as file:
        file.write(updated)
        file.flush()
        os.fsync(file.fileno())
    os.chmod(temporary, 0o600)
    os.replace(temporary, path)
except BaseException:
    os.unlink(temporary)
    raise

if created:
    print(f"Wrote {path} (0600) from .env.example, with the managed block appended.")
elif previous == rendered:
    print(f"{path} is already correct; the managed block is unchanged.")
else:
    print(f"Refreshed the managed block in {path}. Nothing outside it was touched.")

if operator_key:
    print("APP_KEY is set outside the block; left as it is.")
PY

cat <<EOF

$(printf '\033[1m==> Provisioned.\033[0m')

The droplet has Docker, a firewall, a $DEPLOY_USER user, a checkout of
$BRANCH at $DEPLOY_PATH, and a .env whose managed block carries the
infrastructure settings. Every application secret is yours to fill in — APP_KEY
is the exception, generated once and never rotated.

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
