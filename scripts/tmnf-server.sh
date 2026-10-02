#!/usr/bin/env bash
#
# A local TrackMania Nations Forever dedicated server in Docker, for testing the
# TMNF integration (plan "Trackmania und Restposten", P1).
#
#   scripts/tmnf-server.sh up       build the image if needed, start the server, wait for XML-RPC
#   scripts/tmnf-server.sh down     stop and remove the container (the image stays)
#   scripts/tmnf-server.sh status   running or not, and whether XML-RPC answers the GBXRemote 2 handshake
#
# Source: Nadeo's official Linux dedicated server, build 2011-02-21 (the last
# one), http://files2.trackmaniaforever.com/TrackmaniaServer_2011-02-21.zip,
# checksum-pinned in docker/tmnf/Dockerfile. Nothing of the archive is stored
# in this repo; `up` downloads it once into the image.
#
# LAN mode (`/lan`): no master-server account, so no login, no ladder and no
# listing on the internet. The track of the week is a Nadeo stock track copied
# from the same archive into GameData/Tracks/Challenges/League/.
#
# Settings, read from .env (never printed):
#   TMNF_XMLRPC_HOST      where Laravel reaches XML-RPC (default 127.0.0.1)
#   TMNF_XMLRPC_PORT      host port of XML-RPC, bound to 127.0.0.1 only (default 5005)
#   TMNF_XMLRPC_USER      the authorization level Laravel logs in as (default SuperAdmin)
#   TMNF_XMLRPC_PASSWORD  its password; generated into .env on the first `up` when missing
#   TMNF_SERVER_NAME      the server name players see (default "Einundzwanzig eSports")
#   TMNF_GAME_PORT        host port for players, tcp+udp (default 2350)
#   TMNF_GAME_BIND        address the game ports bind to (default 127.0.0.1; 0.0.0.0 for the LAN)
#
# Its own container, network and image names; no other project's container is touched.
#
set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER=einundzwanzig-esports-tmnf
NETWORK=einundzwanzig-esports-tmnf
IMAGE=einundzwanzig-esports-tmnf:2011-02-21
RUNTIME=docker/tmnf/runtime

# The value of KEY in .env, without printing it. Empty when missing.
env_value() {
    [ -f .env ] || return 0
    grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

setting() {
    local value
    value=$(env_value "$1")
    printf '%s' "${value:-$2}"
}

handshake() {
    local host=$1 port=$2
    # GBXRemote: the server greets with a 4-byte little-endian length and "GBXRemote 2".
    timeout 3 bash -c "exec 3<>/dev/tcp/$host/$port && head -c 15 <&3" 2>/dev/null | tail -c 11 | grep -q 'GBXRemote 2'
}

write_config() {
    local password=$1 name=$2
    mkdir -p "$RUNTIME"
    chmod 700 "$RUNTIME"
    umask 077
    # Only the SuperAdmin level is reachable: Admin and User get passwords nobody knows.
    cat > "$RUNTIME/league_cfg.txt" <<CFG
<?xml version="1.0" encoding="utf-8" ?>
<dedicated>
	<authorization_levels>
		<level><name>SuperAdmin</name><password>${password}</password></level>
		<level><name>Admin</name><password>$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')</password></level>
		<level><name>User</name><password>$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')</password></level>
	</authorization_levels>
	<masterserver_account>
		<login></login>
		<password></password>
		<validation_key></validation_key>
	</masterserver_account>
	<server_options>
		<name>${name}</name>
		<comment>Einundzwanzig eSports league server</comment>
		<hide_server>0</hide_server>
		<max_players>32</max_players>
		<password></password>
		<max_spectators>32</max_spectators>
		<password_spectator></password_spectator>
		<ladder_mode>inactive</ladder_mode>
		<ladder_serverlimit_min>0</ladder_serverlimit_min>
		<ladder_serverlimit_max>50000</ladder_serverlimit_max>
		<enable_p2p_upload>False</enable_p2p_upload>
		<enable_p2p_download>False</enable_p2p_download>
		<callvote_timeout>60000</callvote_timeout>
		<callvote_ratio>-1</callvote_ratio>
		<allow_challenge_download>True</allow_challenge_download>
		<autosave_replays>False</autosave_replays>
		<autosave_validation_replays>False</autosave_validation_replays>
		<referee_password></referee_password>
		<referee_validation_mode>0</referee_validation_mode>
		<use_changing_validation_seed>False</use_changing_validation_seed>
	</server_options>
	<system_config>
		<connection_uploadrate>8192</connection_uploadrate>
		<connection_downloadrate>8192</connection_downloadrate>
		<force_ip_address></force_ip_address>
		<server_port>2350</server_port>
		<server_p2p_port>3450</server_p2p_port>
		<client_port>0</client_port>
		<bind_ip_address></bind_ip_address>
		<use_nat_upnp></use_nat_upnp>
		<p2p_cache_size>600</p2p_cache_size>
		<xmlrpc_port>5000</xmlrpc_port>
		<xmlrpc_allowremote>True</xmlrpc_allowremote>
		<blacklist_url></blacklist_url>
		<guestlist_filename></guestlist_filename>
		<blacklist_filename></blacklist_filename>
		<packmask>stadium</packmask>
		<allow_spectator_relays>False</allow_spectator_relays>
		<use_proxy>False</use_proxy>
		<proxy_login></proxy_login>
		<proxy_password></proxy_password>
	</system_config>
</dedicated>
CFG
    # The server runs as uid 10021 inside the container and must read the file.
    chmod 644 "$RUNTIME/league_cfg.txt"
}

up() {
    if docker container inspect "$CONTAINER" >/dev/null 2>&1; then
        echo "tmnf-server: $CONTAINER exists already; run '$0 down' first or '$0 status'." >&2
        exit 1
    fi

    local password
    password=$(env_value TMNF_XMLRPC_PASSWORD)

    if [ -z "$password" ]; then
        password=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
        [ -f .env ] && [ -n "$(tail -c 1 .env)" ] && echo >> .env
        echo "TMNF_XMLRPC_PASSWORD=$password" >> .env
        echo "tmnf-server: generated a SuperAdmin password into .env (TMNF_XMLRPC_PASSWORD, not shown)."
    fi

    if ! printf '%s' "$password" | grep -Eq '^[A-Za-z0-9._-]{8,64}$'; then
        echo "tmnf-server: TMNF_XMLRPC_PASSWORD must be 8-64 letters, digits, dots, dashes or underscores (it goes into an XML file)." >&2
        exit 1
    fi

    local name host port game_port game_bind
    name=$(setting TMNF_SERVER_NAME 'Einundzwanzig eSports')
    host=$(setting TMNF_XMLRPC_HOST 127.0.0.1)
    port=$(setting TMNF_XMLRPC_PORT 5005)
    game_port=$(setting TMNF_GAME_PORT 2350)
    game_bind=$(setting TMNF_GAME_BIND 127.0.0.1)

    if ! printf '%s' "$name" | grep -Eq '^[A-Za-z0-9 ._-]{1,40}$'; then
        echo "tmnf-server: TMNF_SERVER_NAME must be 1-40 letters, digits, spaces, dots or dashes." >&2
        exit 1
    fi

    write_config "$password" "$name"

    if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
        echo "tmnf-server: building $IMAGE (downloads Nadeo's server archive once)..."
        docker build -q -t "$IMAGE" docker/tmnf >/dev/null
    fi

    docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK" >/dev/null

    docker run -d --name "$CONTAINER" --network "$NETWORK" \
        --cpus 1 --memory 256m \
        -p "127.0.0.1:$port:5000/tcp" \
        -p "$game_bind:$game_port:2350/tcp" -p "$game_bind:$game_port:2350/udp" \
        -v "$PWD/$RUNTIME/league_cfg.txt:/tmnf/GameData/Config/league_cfg.txt:ro" \
        -v "$PWD/docker/tmnf/league.txt:/tmnf/GameData/Tracks/MatchSettings/league.txt:ro" \
        "$IMAGE" >/dev/null

    for _ in $(seq 1 60); do
        if handshake "$host" "$port"; then
            echo "tmnf-server: up. XML-RPC on $host:$port (GBXRemote 2), players on $game_bind:$game_port, LAN mode."
            return 0
        fi

        if [ "$(docker container inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]; then
            echo "tmnf-server: the server stopped while starting. Its log:" >&2
            docker logs --tail 40 "$CONTAINER" >&2 || true
            exit 1
        fi

        sleep 1
    done

    echo "tmnf-server: XML-RPC did not answer within 60 s. Its log:" >&2
    docker logs --tail 40 "$CONTAINER" >&2 || true
    exit 1
}

down() {
    if docker container inspect "$CONTAINER" >/dev/null 2>&1; then
        docker rm -f "$CONTAINER" >/dev/null
        echo "tmnf-server: $CONTAINER removed."
    else
        echo "tmnf-server: $CONTAINER is not there."
    fi

    docker network inspect "$NETWORK" >/dev/null 2>&1 && docker network rm "$NETWORK" >/dev/null || true
}

status() {
    local host port
    host=$(setting TMNF_XMLRPC_HOST 127.0.0.1)
    port=$(setting TMNF_XMLRPC_PORT 5005)

    if [ "$(docker container inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]; then
        echo "tmnf-server: down."
        exit 3
    fi

    if handshake "$host" "$port"; then
        echo "tmnf-server: up, XML-RPC answers on $host:$port."
    else
        echo "tmnf-server: the container runs, but XML-RPC on $host:$port does not answer." >&2
        exit 2
    fi
}

case "${1:-}" in
    up) up ;;
    down) down ;;
    status) status ;;
    *)
        echo "usage: $0 up|down|status" >&2
        exit 64
        ;;
esac
