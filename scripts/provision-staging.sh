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
#     to edit anything the operator wrote.
#   - Nothing outside the block is ever rewritten, so no secret can be lost to
#     a missed match. A DB_PASSWORD that Postgres is already using cannot be
#     recovered, which is why this is worth the slightly odd-looking file. The
#     rest of the file IS read, for two bounded things — an APP_KEY already set,
#     and an unclosed quote — and both fail towards leaving it alone.
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

if [ "$#" -gt 1 ]; then
    echo "Too many arguments: this takes one hostname, or --print-key." >&2
    usage
    exit 1
fi

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
# So the script stops *rewriting* by pattern. It owns one delimited block at the
# end of the file and rewrites only that. Last definition wins, so the block's
# values win whatever the rest of the file says or how it spells it — and
# because nothing outside the block is ever rewritten, no secret can be
# destroyed by a bad match.
#
# It still reads the rest of the file for exactly two things, both bounded: an
# APP_KEY the operator has set, which it must not overwrite, and an unclosed
# quote, which would swallow the block and make the run a silent no-op. Both
# fail towards leaving the file alone.
sudo -u "$DEPLOY_USER" python3 - "$ENV_FILE" "$DEPLOY_PATH/.env.example" "$SERVER_NAME" <<'PY'
import base64, os, pathlib, re, secrets, sys, tempfile

path, example, server_name = (pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]),
                              sys.argv[3])

OPEN = "# >>> provision-staging.sh — managed block; re-run the script to change it"
CLOSE = "# <<< provision-staging.sh — end of managed block"


def fail(message):
    print(f"\n{message}\n", file=sys.stderr)
    raise SystemExit(1)


def read(target):
    """Bytes in, bytes out, newlines and all.

    surrogateescape rather than strict: a secret with a stray non-UTF-8 byte in
    it is somebody's password, and the right response is to carry it through
    untouched, not to abort with a traceback. newline="" so CRLF survives — this
    stage promises not to touch what it did not write, and silently converting
    every line ending in the file would break that promise wholesale.
    """
    with open(target, "r", encoding="utf-8", errors="surrogateescape",
              newline="") as handle:
        return handle.read()


def unterminated_quote(text):
    """The line number of a quoted value that is never closed, or None.

    Dotenv values may span lines, so an unclosed quote swallows everything
    after it — including a block appended at the end, which then resolves to
    nothing at all while this script reports success. Detected and refused
    rather than papered over.
    """
    assignment = re.compile(
        r"""^[ \t]*(?:export[ \t]+)?[A-Za-z_][A-Za-z0-9_]*[ \t]*=[ \t]*(.*)$""")
    quote = None
    opened_at = None

    for number, line in enumerate(text.splitlines(), start=1):
        if quote is None:
            match = assignment.match(line)

            if not match:
                continue

            value = match.group(1)

            if not value[:1] in ('"', "'"):
                continue

            quote, opened_at, rest = value[0], number, value[1:]
        else:
            rest = line

        index = 0
        while index < len(rest):
            character = rest[index]

            if character == "\\" and quote == '"':
                index += 2
                continue

            if character == quote:
                quote, opened_at = None, None
                break

            index += 1

    return opened_at


def value_is_set(raw):
    """Does this right-hand side look like a non-empty value?

    Only ever asked about APP_KEY, and only to decide whether to leave the
    operator's own line alone. It is a heuristic, not Dotenv: the cases in
    ProvisionEnvBlockTest are the ones it is known to get right, and that list
    is the claim — not "every spelling", which is a promise a regex over a
    grammar this script deliberately does not implement cannot keep.

    The spellings that matter are the ones that look set and resolve to empty,
    because an empty APP_KEY means the application will not boot: `APP_KEY=""`,
    `APP_KEY= # rotate me`, and the cross-product `APP_KEY="" # rotate me`.
    Interpolation is handled by the caller, which cannot resolve it either.
    """
    value = raw.strip()

    if value[:1] in ('"', "'"):
        closing = value.find(value[0], 1)

        # An unterminated quote here is caught earlier and refused; treat it as
        # set so this function never becomes the thing that overwrites a key.
        return True if closing == -1 else value[1:closing] != ""

    # Unquoted: the value ends at an inline comment, and a value that is
    # nothing but a comment is empty.
    return value.split(" #")[0].split("\t#")[0].strip().lstrip("#").strip() != "" \
        and not value.startswith("#")


# A symlinked .env is followed, not replaced: os.replace() onto the link would
# swap the link itself for a regular file and orphan whatever it pointed at.
if path.is_symlink():
    path = path.resolve()

created = not path.exists()
text = read(example) if created else read(path)
original = text

# Every well-formed managed block is removed and one fresh one appended, so a
# stray copy left by a hand-edit is absorbed rather than stacked.
#
# The scan is sequential and refuses anything it cannot read unambiguously. A
# plain non-greedy regex would match from a stray *opening* delimiter to the
# real block's *closing* one and splice out every secret in between — which is
# the failure this whole design exists to make impossible, arrived at by
# matching too much rather than too little.
def find_line(haystack, needle, start=0):
    """Like str.find, but only matches at the start of a line.

    A plain substring search would splice through any line that merely quotes
    both delimiters — a NOTE="…" describing them, say — deleting its contents
    while the script reported that nothing outside the block was touched. Only
    the start is anchored, not the end, so a delimiter line that has picked up
    trailing whitespace is still recognised as ours rather than stacked on.
    """
    at = start

    while True:
        at = haystack.find(needle, at)

        if at == -1 or at == 0 or haystack[at - 1] == "\n":
            return at

        at += len(needle)


blocks = []
cursor = 0

while True:
    open_at = find_line(text, OPEN, cursor)

    if open_at == -1:
        break

    close_at = find_line(text, CLOSE, open_at)
    next_open = find_line(text, OPEN, open_at + len(OPEN))

    if close_at == -1 or (next_open != -1 and next_open < close_at):
        fail(f"{path} has a managed-block opening delimiter with no closing one.\n"
             "Refusing to guess where the block ends, because guessing wrong\n"
             "would delete whatever sits between it and the next close. Remove\n"
             "the stray line, or close the block by hand, then re-run.")

    stop = close_at + len(CLOSE)
    blocks.append((open_at, stop))
    cursor = stop

# A closing delimiter with no opener is the mirror case, and it means the same
# thing: the file has been hand-edited into a shape this script cannot read
# unambiguously. Duplicating the CLOSE line inside a block leaves the tail of
# the old block stranded as loose settings lines, which is not something to
# half-repair.
for begin, stop in reversed(blocks):
    text = text[:begin] + text[stop:]

if find_line(text, CLOSE) != -1:
    fail(f"{path} has a managed-block closing delimiter with no opening one.\n"
         "Refusing to guess which lines above it belong to the block. Remove the\n"
         "stray line, or restore the opener, then re-run.")

text = original

# The last one is the one whose APP_KEY carries forward.
previous = text[blocks[-1][0]:blocks[-1][1]] if blocks else ""

for begin, stop in reversed(blocks):
    text = text[:begin] + text[stop:]

line = unterminated_quote(text)

if line is not None:
    fail(f"{path} line {line} opens a quoted value that is never closed.\n"
         "Dotenv would swallow everything after it, including the settings this\n"
         "script appends — so the run would look successful and configure\n"
         "nothing. Close the quote, then re-run.")

# Match whatever the file already uses. Appending LF lines to a CRLF file
# would leave a mixed-ending .env, which is precisely the kind of edit this
# stage promises not to make to somebody else's content.
newline = "\r\n" if "\r\n" in text else "\n"
body = text.rstrip("\r\n")

# APP_KEY, in three cases, none of which may rotate a key already in use:
#
#   the operator defines one outside our block  -> leave it alone entirely
#   our previous block had one                  -> reuse that exact value
#   neither                                     -> generate one
#
# The scan below is a regex over a grammar this script otherwise refuses to
# parse. It is a deliberate exception, and the direction that bites is a false
# *positive* — the regex seeing a key where Dotenv sees none, so nothing is
# generated and the box will not boot. That is loud and immediate. A false
# negative writes a key into the block which then wins over one the regex
# missed; nothing outside the block is rewritten, so theirs is still in the
# file and deleting the block restores it.
#
# Neither is silent, which is the property being bought. An interpolated value
# is the one case that cannot be judged from the file alone, so it is reported
# rather than guessed at.
operator_values = re.findall(
    r"^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=(.*)$", body, re.MULTILINE)
operator_last = operator_values[-1] if operator_values else None
operator_key = operator_last is not None and value_is_set(operator_last)
# `APP_KEY="${LEGACY_KEY}"` resolves to whatever LEGACY_KEY holds, which this
# script cannot know. Theirs is left alone — never rotate a key that may be in
# use — but silently doing so is how a box ends up not booting, so it is said.
interpolated = operator_key and "${" in operator_last

app_key = None

if not operator_key:
    reused = re.search(r"^APP_KEY=(\S+)\s*$", previous, re.MULTILINE)
    app_key = reused.group(1) if reused else (
        "base64:" + base64.b64encode(secrets.token_bytes(32)).decode())

settings = [
    ("APP_ENV", "staging"),
    ("APP_DEBUG", "false"),
    # The product's own name, and the name on mail the product itself writes.
    #
    # These are in the managed block rather than left to .env.example because
    # the .env stage copies the example only when the file is **absent** — so a
    # box provisioned before APP_PRODUCT_NAME existed would never gain it, and
    # MAIL_FROM_NAME would keep resolving to "${APP_NAME}", which is
    # deliberately still the pre-rename codename. Dotenv reads the last
    # definition, so these win over an older interpolation above.
    #
    # VITE_APP_NAME is deliberately **not** here. Vite compiles it into the
    # bundle at build time, and the image builds with `cp .env.example .env`
    # (.dockerignore excludes .env), so the runtime file cannot reach it. The
    # value that matters for the browser tab is .env.example's, and setting it
    # here would look like a fix while changing nothing.
    ("APP_PRODUCT_NAME", "Goldieflow"),
    ("MAIL_FROM_NAME", "Goldieflow"),
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

rendered = newline.join(lines) + newline
updated = body + newline + newline + rendered

# Anything that sat after the last block is being moved above the new one.
moved_above = bool(blocks) and original[blocks[-1][1]:].strip() != ""

# Through a temporary file in the same directory, so the .env is never seen
# half-written: a short write on a full disk would otherwise truncate it.
handle, temporary = tempfile.mkstemp(dir=path.parent, prefix=".env.")
try:
    with os.fdopen(handle, "w", encoding="utf-8", errors="surrogateescape",
                   newline="") as file:
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
elif updated == original:
    print(f"{path} is already correct; nothing changed.")
else:
    print(f"Refreshed the managed block in {path}. Nothing outside it was rewritten.")

    # Their lines are intact, byte for byte — but the block is always moved to
    # the end, so anything that was below it is now above it and the block's
    # values win over it. That is the documented design and it is still a thing
    # somebody needs told, because "nothing was rewritten" reads as "nothing
    # changed for me" and it is not.
    if moved_above:
        print("Lines that were below the block are now above it, so the block's\n"
              "values take precedence over them. Edit the block to change those.")

if interpolated:
    print("APP_KEY is set outside the block to an interpolated value, which this\n"
          "script cannot resolve. It has been left alone and no key was generated.\n"
          "Check that APP_KEY is not empty before starting the stack.")
elif operator_key:
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
     MAIL_HOST/PORT/USERNAME/ MAIL_REDIRECT_TO   <- set this before anything
       PASSWORD/FROM_ADDRESS     can send; blank means NO redirection, and
     SENTRY_LARAVEL_DSN          every message goes to its real recipient
     AWS_* (Spaces bucket)
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
