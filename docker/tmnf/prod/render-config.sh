#!/usr/bin/env bash
# Writes config/league_cfg.txt for the production TMNF server from ./.env.
# Never prints a secret. Values: TMNF_XMLRPC_PASSWORD (generated when missing),
# TMNF_SERVER_NAME, TMNF_SERVER_LOGIN / TMNF_SERVER_PASSWORD /
# TMNF_SERVER_VALIDATION (the master-server account; empty = LAN only),
# TMNF_FORCE_IP (the host's public address: inside Docker the server only sees
# its bridge address and would announce that to the master server).
set -euo pipefail
cd "$(dirname "$0")"
touch .env && chmod 600 .env

# A value may be written bare or in one pair of quotes, as in any .env file.
get() {
    local value
    value=$(grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true)
    case "$value" in
        \"*\") value=${value#\"}; value=${value%\"} ;;
        \'*\') value=${value#\'}; value=${value%\'} ;;
    esac
    printf '%s' "$value"
}

if [ -z "$(get TMNF_XMLRPC_PASSWORD)" ]; then
    printf 'TMNF_XMLRPC_PASSWORD=%s\n' "$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> .env
fi

password=$(get TMNF_XMLRPC_PASSWORD)
name=$(get TMNF_SERVER_NAME); name=${name:-Einundzwanzig eSports}
login=$(get TMNF_SERVER_LOGIN); account=$(get TMNF_SERVER_PASSWORD); validation=$(get TMNF_SERVER_VALIDATION); force_ip=$(get TMNF_FORCE_IP)

for value in "$password" "$name" "$login" "$account" "$validation" "$force_ip"; do
    if printf '%s' "$value" | grep -q '[<>&"]'; then echo "render-config: a value contains < > & or \" (it goes into XML)." >&2; exit 1; fi
done

random() { head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

mkdir -p config && chmod 755 config
umask 022
cat > config/league_cfg.txt <<CFG
<?xml version="1.0" encoding="utf-8" ?>
<dedicated>
	<authorization_levels>
		<level><name>SuperAdmin</name><password>${password}</password></level>
		<level><name>Admin</name><password>$(random)</password></level>
		<level><name>User</name><password>$(random)</password></level>
	</authorization_levels>
	<masterserver_account>
		<login>${login}</login>
		<password>${account}</password>
		<validation_key>${validation}</validation_key>
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
		<force_ip_address>${force_ip}</force_ip_address>
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
# The server runs as uid 10021 and must read it; the directory is only reachable for forge.
chmod 644 config/league_cfg.txt
chmod 711 .
echo "render-config: config/league_cfg.txt written ($( [ -n "$login" ] && echo 'internet account set' || echo 'no master-server account: LAN only' ))."
