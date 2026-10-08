#!/usr/bin/env bash
#
# deploy.sh — bring this checkout of the 12 Step Toolkit API up to date.
#
#   app_update_toolkit                    (root-owned copy of bin/app_update_toolkit)
#
#   …/api/deploy.sh                       deploy origin/main (run as the site's user)
#   …/api/deploy.sh --check               report only: what would be pulled, health, smoke tests
#   …/api/deploy.sh --rollback [commit]   go back to the commit before the last deploy (or <commit>)
#
# Run it as the user that owns the checkout. bin/app_update_toolkit does that
# for you when you are root, so normally you never call this file directly.
#
# What a deploy does, in order:
#
#   while the site is still up    checks, `git fetch`, works out what is coming
#   site down (503 page)          fast-forward to origin/main, composer install,
#                                 migrate, rebuild caches, restart the queue worker
#   site up                       reload PHP-FPM, smoke-test the live URLs, app:check
#
# A deploy with nothing new to pull, no pending migration and nothing for
# composer to do never takes the site down: it just rebuilds the caches (handy
# after editing .env).
#
# If a step fails BEFORE any migration ran, the previous commit is put back
# automatically and the site comes up on it. If it fails AFTER a migration ran,
# the site stays down on purpose and the script tells you your options — old
# code against a new schema is not something to decide automatically.
#
# ── What is different here from the AA Big Book script this is ported from ──
#
#  * **The checkout root IS the application root.** aa-bigbook is one repository
#    holding a website and an api/ subfolder; 12steptoolkit-api is its own
#    repository whose root is this directory. Every path built from the repo
#    root has to cope with an empty relative prefix, which is what $REL is for.
#  * **No content import.** There is no bundled literature to bring in.
#  * **The noindex check runs the other way.** There, the application is the
#    website and `noindex` on production is the mistake. Here nginx grafts
#    /console, /api/v2 and /up onto 12steptoolkit.com while the static export
#    keeps the document root, so `noindex` is CORRECT — until the day that root
#    moves here, which is the day it becomes a rankings-losing mistake.
#    SITE_SERVES_WEBSITE decides which check applies, so neither state needs
#    remembering. docs/WEBSITE_TAKEOVER.md.
#  * **No migration ever alters an adopted table.** The migrations create this
#    application's own tables only, so a deploy cannot damage the data the two
#    shipped apps are still reading and writing. That is why a failed deploy
#    here is recoverable by moving a document root rather than by a restore.
#
# Settings (environment variables, all optional):
#   BRANCH=main  REMOTE=origin  PHP=/opt/plesk/php/8.4/bin/php
#   FPM_UNIT=plesk-php84-fpm_<domain>_<id>.service  COMPOSER_PHAR=/path/composer.phar
#   SMOKE_TEST=0   skip the URL checks (first deploy, before the subdomain resolves)
#
# (--smoke, used by the wrapper after it reloads PHP-FPM as root, runs only the
# URL checks.)
#
# Everything lives in main(), called on the last line, so bash has read the
# whole file before running any of it — this script can safely pull a new
# version of itself.
set -euo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
BRANCH="${BRANCH:-main}"
REMOTE="${REMOTE:-origin}"
NAME="$(basename "${DEPLOY_NAME:-$0}")"

LOG="$APP_DIR/storage/logs/deploy.log"
HISTORY="$APP_DIR/storage/logs/deploy-history.log"
STATE_DIR="$APP_DIR/storage/app/deploy"
LOCK="$APP_DIR/storage/framework/deploy.lock"
FPM_MARKER="$APP_DIR/storage/framework/deploy-fpm-reload"

# Filled in as the run goes, read by finish().
MODE=deploy           # deploy | check | rollback | smoke
STAGE=preflight       # preflight → down → changed → migrating → live (or cachesonly / checked)
MIGRATED=0            # 1 once a migration may have run
WAS_DOWN=0            # 1 if the site was already in maintenance mode when we started
COMPOSER_RAN=0        # 1 once composer has touched vendor/ in this run
FPM_DEFERRED=0        # 1 when root reloads PHP-FPM after this run (and runs the smoke test then)
PREVIOUS=""
TARGET=""
REPO_DIR=""
APP_REL=""            # "" when the checkout root is the application root, which it is here
REL=""                # "" or "sub/dir/" — a prefix safe to paste in front of a repo path
PHP_BIN=""
COMPOSER=""
FPM=""
FPM_SHARED=""         # Plesk's shared pool for this PHP version, when the site has no service of its own
URL=""
HOST=""
ENV_NAME=""
PRE_UP=""             # status of /up before anything changed ("" = not probed, 0 = no answer)
PROBLEMS=0
REPORTED=0            # 1 once the reason for a non-zero exit has been printed
DOWN_AT=0
PENDING_OUTPUT=""

if [ -t 1 ]; then B=$'\033[1m' G=$'\033[32m' Y=$'\033[33m' R=$'\033[31m' D=$'\033[2m' N=$'\033[0m'; else B='' G='' Y='' R='' D='' N=''; fi
say()  { printf '\n%s▸ %s%s\n' "$B" "$*" "$N"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; }
oops() { printf '\n%s✗ %s%s\n' "$R" "$*" "$N" >&2; }
note() { printf '  %s%s%s\n' "$D" "$*" "$N"; }
indent() { sed 's/^/    /'; }
die()  { oops "$1"; shift; local line; for line in "$@"; do printf '%s\n' "$line" | indent >&2; done; REPORTED=1; exit 1; }

usage() { sed -n '3,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

git_() { git -C "$REPO_DIR" "$@"; }
artisan() { (cd "$APP_DIR" && "$PHP_BIN" artisan "$@"); }
short() { git_ rev-parse --short "$1" 2>/dev/null || printf '%s' "${1:0:7}"; }
first_lines() { printf '%s\n' "$1" | grep -v '^[[:space:]]*$' | sed -n "1,${2:-3}p" || true; }

# artisan, silent unless it fails. (Laravel prints errors on stdout, so
# discarding stdout or using --quiet would hide exactly the line you need.)
quietly() {
    local out rc=0
    out="$(artisan "$@" 2>&1)" || rc=$?
    if [ "$rc" -ne 0 ]; then
        first_lines "$out" 12 | indent >&2
        oops "php artisan $* failed"
    fi
    return "$rc"
}

# A value from .env, read (never sourced) the way Laravel reads simple values:
# last assignment wins, unquoted trailing " # comment" dropped, quotes removed.
env_value() {
    local line
    line="$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$APP_DIR/.env" 2>/dev/null | tail -n 1 || true)"
    line="${line#*=}"
    line="$(printf '%s' "$line" | sed -E 's/^[[:space:]]+//')"
    case "$line" in
        \"*) line="${line#\"}"; line="${line%%\"*}" ;;
        \'*) line="${line#\'}"; line="${line%%\'*}" ;;
        *) line="$(printf '%s' "$line" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//')" ;;
    esac
    printf '%s' "$line"
}

# ── PHP-FPM ──────────────────────────────────────────────────────────────────
# Plesk gives each site its own FPM service, e.g.
# plesk-php84-fpm_12steptoolkit.com_12. Reloading the shared
# plesk-php84-fpm.service instead "works" and leaves this site's opcache holding
# the old code, so we look for the site's own unit.
find_fpm_unit() {
    if [ -n "${FPM_UNIT:-}" ]; then printf '%s' "$FPM_UNIT"; return 0; fi
    [ -n "$HOST" ] && command -v systemctl >/dev/null 2>&1 || return 0
    local pattern="plesk-php*-fpm_${HOST}_*.service" units unit
    units="$( { systemctl list-units --all --type=service --no-legend --plain "$pattern" 2>/dev/null || true
                systemctl list-unit-files --type=service --no-legend "$pattern" 2>/dev/null || true; } \
              | awk '{print $1}' | grep -E "^plesk-php[0-9]+-fpm_${HOST//./\\.}_[0-9]+\.service$" | sort -u || true)"
    for unit in $units; do
        if systemctl is-active --quiet "$unit" 2>/dev/null; then printf '%s' "$unit"; return 0; fi
    done
    printf '%s' "${units%%$'\n'*}"
}

reload_fpm() {
    if [ -z "$FPM" ]; then
        # No service of its own: the site runs in Plesk's shared pool for this PHP
        # version. Reloading that would restart PHP for every site on it, and is
        # not needed — opcache re-checks changed files (validate_timestamps).
        if [ -n "$FPM_SHARED" ]; then
            ok "Runs in Plesk's shared $FPM_SHARED — not reloaded; PHP picks up the changed files within seconds"
        else
            warn "No PHP-FPM service found for $HOST — not reloaded. PHP normally notices changed files within seconds."
        fi
        return 0
    fi
    # Run from the root wrapper: root reloads it right after this run. (No sudo
    # attempt here — for a user without sudo rights that mails root an alert.)
    if [ "${DEPLOY_VIA_ROOT:-0}" = 1 ]; then
        printf '%s\n' "$FPM" > "$FPM_MARKER"
        FPM_DEFERRED=1
        ok "$FPM is reloaded by $NAME (as root) right after this run"
        return 0
    fi
    if command -v sudo >/dev/null 2>&1 && sudo -n systemctl reload "$FPM" 2>/dev/null; then
        ok "Reloaded $FPM"
    else
        warn "Could not reload $FPM — $(id -un) may not run it without a password."
        note "One-time fix, as root:  echo '$(id -un) ALL=(root) NOPASSWD: $(command -v systemctl) reload $FPM' > /etc/sudoers.d/12steptoolkit-deploy"
        note "(or run $NAME as root, which reloads it for you)"
    fi
}

# ── Composer ─────────────────────────────────────────────────────────────────
# A real composer.phar, run with OUR PHP. On Plesk `command -v composer` is a
# shell wrapper that picks its own (older) PHP, and feeding that wrapper to PHP
# prints it as text with exit code 0 — a deploy that installs nothing, quietly.
find_composer() {
    local candidate
    for candidate in "${COMPOSER_PHAR:-}" \
        /opt/psa/var/modules/composer/composer.phar /usr/local/psa/var/modules/composer/composer.phar \
        "$HOME/bin/composer.phar" "$HOME/bin/composer" "$HOME/.local/bin/composer" \
        /usr/local/bin/composer.phar /usr/local/bin/composer /usr/bin/composer
    do
        [ -n "$candidate" ] && [ -f "$candidate" ] || continue
        [[ "$(head -c 128 "$candidate" 2>/dev/null | tr -d '\0')" == *'<?php'* ]] || continue
        [[ "$("$PHP_BIN" "$candidate" --version 2>/dev/null || true)" == Composer* ]] || continue
        printf '%s' "$candidate"
        return 0
    done
}

composer_install() {
    say "Composer"
    COMPOSER_RAN=1   # before, not after: a run that fails halfway has still touched vendor/
    "$PHP_BIN" -d memory_limit=-1 "$COMPOSER" install --working-dir="$APP_DIR" \
        --no-dev --optimize-autoloader --no-interaction --no-progress || return 1
    # A no-op install leaves installed.json's date alone; the refresh path below
    # compares it with composer.lock to know whether composer has work to do.
    touch "$APP_DIR/vendor/composer/installed.json" 2>/dev/null || true
}

composer_is_current() {
    [ -f "$APP_DIR/vendor/autoload.php" ] && [ "$APP_DIR/vendor/composer/installed.json" -nt "$APP_DIR/composer.lock" ]
}

require_lock() { # require_lock COMMIT
    git_ cat-file -e "$1:${REL}composer.lock" 2>/dev/null \
        || die "$(short "$1") has no ${REL}composer.lock — composer would install unpinned versions." \
               "Run 'composer update' on your Mac, commit composer.lock, push, then deploy."
}

# ── caches ───────────────────────────────────────────────────────────────────
# After the code changes, the compiled config/routes/events from the last deploy
# no longer match it, and anything that boots Laravel (composer's
# package:discover, migrate, even config:clear) would boot the new code against
# them. Delete the files rather than ask artisan.
drop_compiled_caches() {
    rm -f "$APP_DIR"/bootstrap/cache/config.php "$APP_DIR"/bootstrap/cache/events.php \
          "$APP_DIR"/bootstrap/cache/routes.php "$APP_DIR"/bootstrap/cache/routes-v*.php
}

# Each piece separately rather than optimize:clear, which also empties the
# application cache — the scheduler heartbeat, rate limits and queue locks live
# there and have no reason to be thrown away by a deploy.
clear_caches() {
    quietly config:clear || return 1
    quietly route:clear || return 1
    quietly view:clear || return 1
    quietly event:clear || return 1
}

build_caches() {
    say "Caches"
    clear_caches || return 1
    quietly config:cache || return 1
    quietly route:cache || return 1
    quietly view:cache || return 1
    quietly event:cache || return 1
    ok "Config, routes, views and events cached"
}

storage_dirs() {
    mkdir -p "$APP_DIR"/storage/app/deploy \
        "$APP_DIR"/storage/framework/cache/data "$APP_DIR"/storage/framework/sessions "$APP_DIR"/storage/framework/views \
        "$APP_DIR"/storage/logs "$APP_DIR"/bootstrap/cache
}

# 0 = nothing pending, 1 = pending (or a new database with no migrations table),
# 2 = cannot tell — the database is unreachable or the code does not boot
# (details in $PENDING_OUTPUT).
pending_migrations() {
    local rc=0
    # APP_CONFIG_CACHE pointing nowhere makes artisan read .env as it is now,
    # not the cached copy from the last deploy.
    PENDING_OUTPUT="$(APP_CONFIG_CACHE="$APP_DIR/storage/framework/deploy-probe-config.php" artisan migrate:status --pending=3 2>&1)" || rc=$?
    case "$rc" in
        0) return 0 ;;
        3) return 1 ;;
        *) [[ "${PENDING_OUTPUT,,}" == *"migration table not found"* ]] && return 1
           return 2 ;;
    esac
}

go_down() {
    local secret
    secret="$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
    say "Maintenance mode"
    # From here finish() treats the site as possibly down, even if `down`
    # itself fails halfway or is interrupted.
    STAGE=down
    DOWN_AT=$SECONDS
    # --render stores our own 503 page as static HTML now, while the framework
    # can still render it.
    quietly down --render="errors.503" --retry=30 --refresh=15 --secret="$secret" \
        || die "Could not switch on maintenance mode (above)."
    ok "Showing the 503 page (a crawler is told to come back, and the two shipped apps retry)"
    note "Preview it while it is down: $URL/$secret"
}

go_up() {
    quietly up || return 1
    ok "Up — it was down for $((SECONDS - DOWN_AT))s"
}

# ── smoke tests: the URLs that matter, through the real web server ──────────
http() { # http URL → "status|location|x-robots-tag"   (status 0 = no answer)
    local headers status
    headers="$(curl -sS -o /dev/null -D - --max-time 20 -A "$NAME smoke test" "$1" 2>/dev/null || true)"
    status="$(printf '%s' "$headers" | awk 'toupper($1) ~ /^HTTP\// {code=$2} END {print code+0}')"
    printf '%s|%s|%s' "$status" \
        "$(printf '%s' "$headers" | tr -d '\r' | awk 'tolower($1)=="location:" {v=$2} END {print v}')" \
        "$(printf '%s' "$headers" | tr -d '\r' | awk -F': *' 'tolower($1)=="x-robots-tag" {v=$2} END {print v}')"
}

smoke_enabled() {
    [ "${SMOKE_TEST:-1}" != 0 ] && [ -n "$URL" ] && command -v curl >/dev/null 2>&1
}

check_url() { # check_url PATH STATUS [LOCATION-SUFFIX]
    local result status location
    result="$(http "$URL$1")"
    status="${result%%|*}"; location="${result#*|}"; location="${location%%|*}"
    if [ "$status" = "$2" ] && { [ -z "${3:-}" ] || [[ "$location" == *"$3" ]]; }; then
        ok "$1 → $status${3:+ → $location}"
    else
        oops "$1 answered $status${location:+ → $location} (expected $2${3:+ → …$3})"
        PROBLEMS=$((PROBLEMS + 1))
    fi
}

# Is APP_URL's host the public website's host?
is_website_host() {
    local website
    website="$(env_value SITE_WEBSITE_HOST)"
    website="${website:-12steptoolkit.com}"
    [ -n "$HOST" ] && { [ "$HOST" = "$website" ] || [ "$HOST" = "www.$website" ]; }
}

# Does this installation own `/` on that host, or only the prefixes nginx
# routes to it?
#
# Being ON the website's host is not the same as BEING the website. On
# 12steptoolkit.com the document root stays on the static export and nginx
# sends `/console`, `/api/v2` and `/up` here — so the host matches, `/` is
# somebody else's, and a check that judged `/` would be grading another
# program's work. SITE_SERVES_WEBSITE is the only thing that knows which it is.
serves_website() {
    case "$(env_value SITE_SERVES_WEBSITE | tr '[:upper:]' '[:lower:]')" in
        1 | true | yes | on) return 0 ;;
        *) return 1 ;;
    esac
}

# ── indexability ────────────────────────────────────────────────────────────
# Two mistakes, both of which cost real traffic and neither of which shows up
# in a log:
#
#   * **this application's pages indexable when it is not the website** — a
#     placeholder and a staff login under a brand with 205 ranking URLs,
#     competing with none of them usefully;
#   * **the website serving noindex** — every one of those 205 URLs dropped
#     from the index within days, found a week later on the traffic graph.
#
# Which mistake is possible depends on whether this installation owns `/`, so
# that is what the check branches on rather than on the host. Judging `/` when
# the static export serves it would put a green tick against another program's
# work, which is worse than no check at all.
check_indexability() {
    local robots

    if serves_website; then
        robots="$(http "$URL/")"; robots="${robots##*|}"

        if [[ "$robots" == *noindex* ]]; then
            oops "$HOST is the public website and it is serving X-Robots-Tag: $robots — every indexed URL will be dropped. Set SITE_NOINDEX=false."
            PROBLEMS=$((PROBLEMS + 1))
        else
            ok "/ is indexable, which is right once this application serves the website"
        fi
        return 0
    fi

    # Grafted onto somebody else's document root. The console is the page to
    # ask about: it is the only one a crawler could reach here, and it carries
    # the header unconditionally rather than from `site.noindex`, so this
    # assertion stays true after a takeover flips that flag.
    robots="$(http "$URL/console/login")"; robots="${robots##*|}"

    if [[ "$robots" == *noindex* ]]; then
        ok "/console/login is kept out of search results (X-Robots-Tag: $robots)"
    else
        oops "/console/login is indexable — a staff login page should never be. Check SecurityHeaders."
        PROBLEMS=$((PROBLEMS + 1))
    fi

    note "/ is served by the static export, not by this application — not judged here. docs/WEBSITE_TAKEOVER.md"
}

smoke_test() {
    say "Smoke test ($URL)"
    if [ "${SMOKE_TEST:-1}" = 0 ]; then
        warn "Skipped (SMOKE_TEST=0) — e.g. before the nginx blocks are in"
        return 0
    fi
    command -v curl >/dev/null 2>&1 || { warn "curl is not installed — skipped"; return 0; }
    [ -n "$URL" ] || { warn "APP_URL is not set in .env — skipped"; return 0; }
    if [ "$FPM_DEFERRED" = 1 ] && [ "$MODE" != smoke ]; then
        printf 'smoke %s\n' "$PRE_UP" >> "$FPM_MARKER"
        note "Runs once PHP-FPM has been reloaded (below), so it tests the new code."
        return 0
    fi
    local up
    up="$(http "$URL/up")"
    if [ "${up%%|*}" = 0 ]; then
        if [ -n "$PRE_UP" ] && [ "$PRE_UP" != 0 ]; then
            oops "$URL/up does not answer (no response within 20 s) — it did before the deploy."
            PROBLEMS=$((PROBLEMS + 1))
        else
            warn "Could not reach $URL from this server — open it in a browser instead."
        fi
        return 0
    fi
    check_url /up 200                       # the framework is alive
    check_url /api/v2/health 200            # the app's API answers
    check_url /console 302 /console/login   # a stranger is sent to the login page, not shown the back office
    check_url /console/login 200
    check_url /this-page-does-not-exist 404

    # `/` either way: this application's own route when it serves the website,
    # and otherwise the static export — where a 200 is still worth having,
    # because the commonest way to break that site is an nginx edit made to
    # route a prefix here.
    check_url / 200

    check_indexability
}

health() {
    say "Health"
    artisan about --only=environment,drivers 2>/dev/null | sed 's/^/  /' || true
    artisan app:check || { PROBLEMS=$((PROBLEMS + 1)); oops "app:check found problems (above)."; }
    note "Store credentials are not checked here (they need the network and are not a deploy's business): php artisan billing:check"
}

record() { # record RESULT
    mkdir -p "$STATE_DIR" "$(dirname "$HISTORY")"
    printf '%s  %-8s %-9s %s → %s  migrated=%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$(id -un)" "$MODE" \
        "$(short "${PREVIOUS:-HEAD}")" "$(short HEAD)" "$MIGRATED" "$1" >> "$HISTORY"
}

# ── when something fails ─────────────────────────────────────────────────────
# Called from `if`, where set -e is off: every step checks for itself.
put_previous_back() {
    say "Putting $(short "$PREVIOUS") back"
    git_ reset --hard --quiet "$PREVIOUS" || return 1
    drop_compiled_caches
    if [ "$COMPOSER_RAN" = 1 ]; then composer_install || return 1; fi
    build_caches || { warn "Caches would not build — running without them"; drop_compiled_caches; }
    quietly queue:restart || true
    reload_fpm
    go_up || return 1
}

finish() {
    local code=$?
    trap '' INT TERM HUP     # a second Ctrl-C must not cut the clean-up short
    trap - EXIT
    [ "$code" -eq 0 ] && return 0

    case "$STAGE" in
        preflight)
            oops "Stopped before changing anything — the site was not touched."
            ;;
        checked)
            [ "$REPORTED" = 1 ] || oops "The check found problems (above). Nothing was changed."
            ;;
        live)
            [ "$REPORTED" = 1 ] || oops "$(short HEAD) is live, but something above needs a look."
            ;;
        cachesonly)
            oops "Refreshing failed (above). Removing the compiled caches so the site runs on .env and the code as they are."
            drop_compiled_caches
            record "FAILED refreshing"
            ;;
        *)
            # Went down (or tried to) but nothing else happened yet: just come back up.
            if [ "$WAS_DOWN" = 0 ] && [ "$COMPOSER_RAN" = 0 ] && [ "$MIGRATED" = 0 ] \
                && [ "$(git_ rev-parse HEAD 2>/dev/null)" = "$PREVIOUS" ]; then
                if quietly up; then
                    oops "Nothing was changed; the site is up again."
                    exit "$code"
                fi
            fi
            # Never bring up a site that an earlier run left down on purpose.
            if [ "$MODE" = deploy ] && [ "$MIGRATED" = 0 ] && [ "$WAS_DOWN" = 0 ] && [ -n "$PREVIOUS" ]; then
                oops "Failed before any migration ran. Going back to $(short "$PREVIOUS")."
                if put_previous_back; then
                    record "FAILED, reverted to $(short "$PREVIOUS")"
                    oops "The site is up again on $(short "$PREVIOUS"), the commit it was on. Fix the problem above and run $NAME again."
                    exit "$code"
                fi
                oops "Putting the previous commit back failed too."
            fi
            record "FAILED at $STAGE, site left down"
            oops "THE SITE IS STILL DOWN, on purpose."
            if [ "$MIGRATED" = 1 ]; then
                printf '%s\n' \
                    "  A migration may have run, so the database may no longer match the old code" \
                    "  and neither coming up nor going back is automatically safe. Read $LOG, then:" "" \
                    "    fix forward       push a fix, then run $NAME again" \
                    "    come up as is     cd $APP_DIR && $PHP_BIN artisan up" \
                    "    go back           reverse the migration yourself first, then" \
                    "                      $NAME --rollback $(short "$PREVIOUS") --force" "" \
                    "  No migration in this application alters a legacy table, so the two shipped" \
                    "  apps are reading and writing their own data throughout, whatever you decide." >&2
            elif [ "$MODE" = rollback ]; then
                printf '%s\n' "  Read $LOG, fix the problem, then run $NAME --rollback $(short "$TARGET") again" \
                    "  (or bring it up as it is: cd $APP_DIR && $PHP_BIN artisan up)." >&2
            else
                printf '%s\n' "  Read $LOG, fix the problem, then run $NAME again" \
                    "  (or bring it up as it is: cd $APP_DIR && $PHP_BIN artisan up)." >&2
            fi
            ;;
    esac
    exit "$code"
}

# ═════════════════════════════════════════════════════════════════════════════
main() {
    local rollback_to="" force=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --check) MODE=check ;;
            --smoke) MODE=smoke ;;
            --rollback) MODE=rollback; if [ -n "${2:-}" ] && [ "${2#-}" = "$2" ]; then rollback_to="$2"; shift; fi ;;
            --force) force=1 ;;
            -h|--help) usage; exit 0 ;;
            *) usage >&2; exit 2 ;;
        esac
        shift
    done

    # ── who and where ────────────────────────────────────────────────────────
    # Never as root: git refuses a repository owned by somebody else ("dubious
    # ownership"), so a root deploy pulls nothing and rebuilds the OLD code.
    if [ "$(id -u)" -eq 0 ]; then
        die "Do not run deploy.sh as root." "Use app_update_toolkit (it switches to the site's user) — docs/DEPLOYMENT.md §6."
    fi
    [ -f "$APP_DIR/artisan" ] || die "No artisan in $APP_DIR."
    REPO_DIR="$(git -C "$APP_DIR" rev-parse --show-toplevel 2>/dev/null)" || die "$APP_DIR is not inside a git checkout."

    # In this repository the checkout root IS the application root, so APP_REL
    # is empty and $REL is empty with it. Keeping both means a future move of
    # the application into a subfolder needs no other change here — and means
    # "${APP_DIR#"$REPO_DIR"/}", which leaves the whole path when the two are
    # equal, is never used to build a path.
    if [ "$REPO_DIR" = "$APP_DIR" ]; then APP_REL=""; else APP_REL="${APP_DIR#"$REPO_DIR"/}"; fi
    REL="${APP_REL:+$APP_REL/}"

    [ -w "$APP_DIR/storage" ] || die "$(id -un) cannot write to $APP_DIR/storage." "The checkout should belong to the site's user: chown -R <user>:psacln $REPO_DIR"
    [ -f "$APP_DIR/.env" ] || die "There is no $APP_DIR/.env yet." "First deploy: see docs/DEPLOYMENT.md §4."
    [ -n "$(env_value APP_KEY)" ] || die "APP_KEY is empty in .env." "docs/DEPLOYMENT.md §4 — and keep a copy of it somewhere safe."

    URL="$(env_value APP_URL)"; URL="${URL%/}"
    HOST="$(printf '%s' "$URL" | sed -E 's#^[a-z]+://##; s#[:/].*$##')"
    ENV_NAME="$(env_value APP_ENV)"; ENV_NAME="${ENV_NAME:-production}"

    # .env sanity, before anything else touches it. A setting written twice
    # means the second line silently wins — typically an editor that saved an
    # old copy of the file over a fix.
    local dupes db_connection
    dupes="$(grep -oE '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*[[:space:]]*=' "$APP_DIR/.env" | tr -d ' \t=' | sort | uniq -d | tr '\n' ' ' || true)"
    [ -z "$dupes" ] || die ".env sets these more than once, and the last line wins: $dupes" \
        "Keep one line for each. If you fixed .env before, something saved an older copy over it — don't edit .env in Plesk's File Manager."
    db_connection="$(env_value DB_CONNECTION)"
    if [ "$ENV_NAME" != local ] && [ "${db_connection:-sqlite}" = sqlite ]; then
        die "DB_CONNECTION in .env is '${db_connection:-(not set)}' — on the server it must be mysql or mariadb." \
            "This application adopts an existing MySQL database; sqlite would create an empty one and answer from it."
    fi
    FPM="$(find_fpm_unit)"

    # PHP: whatever the site's FPM runs, else the newest supported Plesk PHP.
    PHP_BIN="${PHP:-}"
    if [ -z "$PHP_BIN" ] && [[ "$FPM" =~ ^plesk-php([0-9])([0-9]+)-fpm ]]; then
        PHP_BIN="/opt/plesk/php/${BASH_REMATCH[1]}.${BASH_REMATCH[2]}/bin/php"
    fi
    if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
        PHP_BIN=""
        for candidate in /opt/plesk/php/8.4/bin/php /opt/plesk/php/8.5/bin/php "$(command -v php || true)"; do
            if [ -n "$candidate" ] && [ -x "$candidate" ]; then PHP_BIN="$candidate"; break; fi
        done
    fi
    [ -n "$PHP_BIN" ] || die "No PHP found. Set PHP=/opt/plesk/php/8.4/bin/php"
    "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' \
        || die "$PHP_BIN is PHP $("$PHP_BIN" -r 'echo PHP_VERSION;'); this application needs 8.4.1 or newer (composer.lock is resolved for 8.4)."

    mkdir -p "$(dirname "$LOG")" "$STATE_DIR"

    # One deploy at a time.
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK"
        flock -n 9 || die "Another deploy of this checkout is running (lock: $LOCK)."
    fi

    # A dropped SSH connection must not kill a deploy halfway: ignore hangups
    # (tee and every child inherit that), so the run finishes or reverts.
    trap '' HUP
    # Everything from here on is also written to storage/logs/deploy.log. tee
    # ignores Ctrl-C so the log still gets the end of an interrupted deploy.
    exec > >(trap '' INT TERM; exec tee -a "$LOG") 2>&1
    trap finish EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM

    say "$NAME — $MODE of $ENV_NAME ($URL) started $(date '+%Y-%m-%d %H:%M:%S') by $(id -un)"
    if [ -z "$FPM" ] && command -v systemctl >/dev/null 2>&1; then
        local shared
        shared="plesk-php$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION, PHP_MINOR_VERSION;')-fpm.service"
        if systemctl is-active --quiet "$shared" 2>/dev/null; then FPM_SHARED="$shared"; fi
    fi
    local fpm_label="${FPM:-not found}"
    [ -n "$FPM" ] || [ -z "$FPM_SHARED" ] || fpm_label="shared $FPM_SHARED"
    note "checkout $REPO_DIR · branch $BRANCH · PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN) · FPM $fpm_label"
    if serves_website; then
        note "This installation serves the website — the indexability check below is a hard one. docs/WEBSITE_TAKEOVER.md"
    elif is_website_host; then
        note "On the website's host but not serving it: nginx routes /console, /api/v2 and /up here, / stays on the static export."
    fi

    cd "$APP_DIR"

    # ── smoke test only (after the wrapper reloaded PHP-FPM as root) ─────────
    if [ "$MODE" = smoke ]; then
        STAGE=checked
        PRE_UP="${DEPLOY_PRE_UP:-}"
        smoke_test
        if [ "$PROBLEMS" -gt 0 ]; then
            record "smoke test after PHP-FPM reload: $PROBLEMS problem(s)"
            oops "$(short HEAD) is live, but the smoke test found $PROBLEMS problem(s) above."
            printf '  Roll back with: %s --rollback\n' "$NAME"
            REPORTED=1
            exit 1
        fi
        exit 0
    fi

    storage_dirs
    if [ -f "$APP_DIR/storage/framework/down" ]; then
        WAS_DOWN=1
        warn "The site is already in maintenance mode (an earlier deploy stopped, or someone ran artisan down)."
        note "It comes up only if this run succeeds."
    fi

    # ── the code, while the site is still up ─────────────────────────────────
    local branch dirty incoming=0
    branch="$(git_ symbolic-ref --quiet --short HEAD || true)"
    [ "$branch" = "$BRANCH" ] || die "The checkout is on '${branch:-a detached HEAD}', not '$BRANCH'." "git -C $REPO_DIR checkout $BRANCH"

    # A local edit on the server is a stop, not a merge. storage/ and
    # bootstrap/cache/ are runtime state and do not count.
    dirty="$(git_ status --porcelain -- . ":(exclude)${REL}storage" ":(exclude)${REL}bootstrap/cache")"
    [ -z "$dirty" ] || die "The checkout has local changes — deal with them first (nothing was changed):" "$dirty"

    PREVIOUS="$(git_ rev-parse HEAD)"

    if [ "$MODE" != rollback ]; then
        say "Fetching $REMOTE/$BRANCH"
        git_ fetch --quiet "$REMOTE" "$BRANCH" \
            || die "git fetch failed." "Can $(id -un) reach GitHub? Try: git -C $REPO_DIR ls-remote $REMOTE  (deploy key: docs/DEPLOYMENT.md §3)"
        TARGET="$(git_ rev-parse "$REMOTE/$BRANCH")"
        if [ "$TARGET" = "$PREVIOUS" ]; then
            ok "Already at $(short HEAD) — $(git_ log -1 --pretty=%s)"
        elif git_ merge-base --is-ancestor HEAD "$TARGET"; then
            incoming="$(git_ rev-list --count "HEAD..$TARGET")"
            ok "$incoming new commit(s), $(short HEAD) → $(short "$TARGET"):"
            git_ log -n 15 --pretty='      %h %s' "HEAD..$TARGET"
            [ "$incoming" -le 15 ] || note "      … and $((incoming - 15)) more"
        else
            die "This checkout has commits that $REMOTE/$BRANCH does not (it has diverged)." \
                "Nothing was changed. Look at: git -C $REPO_DIR log --oneline $REMOTE/$BRANCH..HEAD"
        fi
    fi

    # ── report only ──────────────────────────────────────────────────────────
    if [ "$MODE" = check ]; then
        git_ cat-file -e "$TARGET:${REL}composer.lock" 2>/dev/null \
            || { oops "$(short "$TARGET") has no ${REL}composer.lock — a deploy would refuse to run."; PROBLEMS=$((PROBLEMS + 1)); }
        [ -f "$HISTORY" ] && { say "Last deploys"; tail -n 5 "$HISTORY" | sed 's/^/  /'; }
        if [ -f "$APP_DIR/vendor/autoload.php" ]; then
            local state=0
            pending_migrations || state=$?
            case "$state" in
                0) ok "No pending migrations" ;;
                1) warn "Migrations are pending (a deploy will run them)" ;;
                *) oops "Cannot read the migrations — is the database reachable?"; first_lines "$PENDING_OUTPUT" 3 | indent; PROBLEMS=$((PROBLEMS + 1)) ;;
            esac
            health
        else
            warn "Dependencies are not installed yet (vendor/ is missing) — the first deploy installs them"
        fi
        smoke_test
        STAGE=checked
        [ "$PROBLEMS" -eq 0 ] || exit 1
        say "All good. Nothing was changed."
        exit 0
    fi

    # ── rollback: choose the commit and make sure it is safe ─────────────────
    if [ "$MODE" = rollback ]; then
        rollback_to="${rollback_to:-$(cat "$STATE_DIR/previous" 2>/dev/null || true)}"
        [ -n "$rollback_to" ] || die "No previous deploy is recorded. Name a commit: $NAME --rollback <commit>"
        TARGET="$(git_ rev-parse --verify --quiet "$rollback_to^{commit}")" || die "Unknown commit: $rollback_to"
        [ "$TARGET" != "$PREVIOUS" ] || die "Already at $(short "$TARGET")."
        git_ merge-base --is-ancestor "$TARGET" HEAD || die "$(short "$TARGET") is not an earlier commit of $BRANCH."
        local schema
        schema="$(git_ diff --no-renames --name-only --diff-filter=AMD "$TARGET" HEAD -- "${REL}database/migrations")"
        if [ -n "$schema" ] && [ "$force" = 0 ]; then
            die "Migrations changed since $(short "$TARGET"); the old code may not work with today's database:" "$schema" "" \
                "Reverse them yourself first (php artisan migrate:rollback --step=N can delete data — read each down() first)," \
                "or push a fix instead. To roll back anyway: $NAME --rollback $(short "$TARGET") --force"
        fi
        say "Rolling back $(short HEAD) → $(short "$TARGET") — $(git_ log -1 --pretty=%s "$TARGET")"
    fi

    require_lock "$TARGET"
    COMPOSER="$(find_composer)"
    [ -n "$COMPOSER" ] || die "No composer.phar found (and no wrapper is good enough)." \
        "Looked in /opt/psa/var/modules/composer/, /usr/local/psa/var/modules/composer/, ~/bin, /usr/local/bin." \
        "Set COMPOSER_PHAR=/path/to/composer.phar"

    # First deploy: nothing runs without vendor/, and the site is not live yet.
    if [ ! -f "$APP_DIR/vendor/autoload.php" ]; then
        note "First run on this checkout: installing dependencies before anything else"
        composer_install
    fi

    # ── the database must answer before anything changes ─────────────────────
    local pending=0
    pending_migrations || pending=$?
    if [ "$pending" = 2 ]; then
        die "Cannot read the migrations table — the database is unreachable or the app does not start:" \
            "$(first_lines "$PENDING_OUTPUT" 3)" "Check DB_* in $APP_DIR/.env. Nothing was changed."
    fi

    # Is the site answering now? Afterwards, silence then means the deploy broke it.
    if smoke_enabled; then
        PRE_UP="$(http "$URL/up")"; PRE_UP="${PRE_UP%%|*}"
    fi

    # ── nothing new: refresh without going down ──────────────────────────────
    if [ "$MODE" = deploy ] && [ "$TARGET" = "$PREVIOUS" ] && [ "$pending" = 0 ] && [ "$WAS_DOWN" = 0 ] && composer_is_current; then
        note "No new code, no pending migrations, nothing for composer — refreshing without maintenance mode"
        STAGE=cachesonly
        build_caches
        say "Workers and PHP"
        quietly queue:restart
        ok "Queue worker told to restart (within a minute)"
        reload_fpm
        STAGE=live
        smoke_test
        health
        record "$([ "$PROBLEMS" -eq 0 ] && echo refreshed || echo "refreshed, $PROBLEMS problem(s)")"
        [ "$PROBLEMS" -eq 0 ] || { oops "Refreshed $(short HEAD), with $PROBLEMS problem(s) above."; REPORTED=1; exit 1; }
        say "Done — $(short HEAD), nothing new to deploy; caches rebuilt in ${SECONDS}s."
        exit 0
    fi

    # ── down ─────────────────────────────────────────────────────────────────
    go_down

    # ── code ─────────────────────────────────────────────────────────────────
    if [ "$MODE" = rollback ]; then
        STAGE=changed
        git_ reset --hard --quiet "$TARGET"
    elif [ "$TARGET" != "$PREVIOUS" ]; then
        STAGE=changed
        git_ merge --ff-only --quiet "$TARGET"
    fi
    ok "Code is at $(short HEAD) — $(git_ log -1 --pretty=%s)"
    drop_compiled_caches

    # Always after a code change, even with the same composer.lock: the
    # optimised autoloader lists every class file, including ours.
    if [ "$COMPOSER_RAN" = 0 ] || [ "$(git_ rev-parse HEAD)" != "$PREVIOUS" ]; then
        STAGE=changed
        composer_install
    fi

    # No Node on this server: the console's CSS is hand-written and lives in public/.
    if grep -rqs '@vite' "$APP_DIR/resources/views"; then
        die "A Blade template now uses @vite, which needs a Node build this deploy does not do." \
            "Either serve the asset from public/ like the rest of the console, or add 'npm ci && npm run build' here."
    fi

    # ── schema ───────────────────────────────────────────────────────────────
    # These migrations create this application's own tables and name no adopted
    # table, so nothing here can damage what the two shipped apps are using.
    if [ "$MODE" = deploy ]; then
        pending=0
        pending_migrations || pending=$?
        case "$pending" in
            0) ok "No migrations to run" ;;
            1) say "Migrating"
               STAGE=migrating
               MIGRATED=1   # set BEFORE: a migration that fails halfway has still changed the database
               artisan migrate --force ;;
            *) die "The new code cannot read the migrations, so nothing was migrated:" "$(first_lines "$PENDING_OUTPUT" 6)" ;;
        esac
    fi

    # ── caches, workers ──────────────────────────────────────────────────────
    build_caches

    say "Workers and PHP"
    quietly queue:restart
    ok "Queue worker told to restart on the new code (within a minute)"
    reload_fpm

    # ── up ───────────────────────────────────────────────────────────────────
    go_up
    STAGE=live
    if [ "$MODE" = rollback ]; then
        printf '%s\n' "$PREVIOUS" > "$STATE_DIR/rolled-back-from"
    elif [ "$TARGET" != "$PREVIOUS" ]; then
        printf '%s\n' "$PREVIOUS" > "$STATE_DIR/previous"
    fi

    smoke_test
    health
    record "$([ "$PROBLEMS" -eq 0 ] && echo ok || echo "$PROBLEMS problem(s)")"
    if [ "$MODE" = rollback ]; then
        warn "$BRANCH is now behind $REMOTE/$BRANCH: the next $NAME will bring $(short "$PREVIOUS") back."
        note "Push a fix (or git revert) before deploying again."
    fi

    if [ "$PROBLEMS" -gt 0 ]; then
        oops "Deployed $(short HEAD), with $PROBLEMS problem(s) above. Previous was $(short "$PREVIOUS")."
        [ "$MODE" = rollback ] || printf '  Roll back with: %s --rollback %s\n' "$NAME" "$(short "$PREVIOUS")"
        REPORTED=1
        exit 1
    fi
    if [ "$(git_ rev-parse HEAD)" = "$PREVIOUS" ]; then
        say "Done — $(short HEAD) is live (${SECONDS}s)."
    elif [ "$MODE" = rollback ]; then
        say "Rolled back to $(short HEAD) in ${SECONDS}s (from $(short "$PREVIOUS"))."
    else
        say "Deployed $(short HEAD) in ${SECONDS}s. Previous was $(short "$PREVIOUS")."
        printf '  Roll back with: %s --rollback\n' "$NAME"
    fi
}

main "$@"
