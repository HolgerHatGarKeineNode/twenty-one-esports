# The official TrackMania Nations/United Forever dedicated server, as Nadeo
# published it (build 2011-02-21, the last one), for local tests of the TMNF
# integration (plan "Trackmania und Restposten", P1). Built by
# scripts/tmnf-server.sh; nothing of Nadeo's archive is stored in this repo.
#
# The Linux binary is a statically linked i386 ELF, so a plain Alpine base runs
# it on an amd64 host without any 32-bit libraries.
FROM alpine:3

ARG TMNF_SERVER_URL=http://files2.trackmaniaforever.com/TrackmaniaServer_2011-02-21.zip
ARG TMNF_SERVER_SHA256=dd021f49c3d58d45ee09f333015bdb35b19243a38fa65f202ca8a88fb6550c0b

WORKDIR /tmnf

RUN wget -q -O /tmp/server.zip "$TMNF_SERVER_URL" \
    && echo "$TMNF_SERVER_SHA256  /tmp/server.zip" | sha256sum -c - \
    && unzip -q /tmp/server.zip -d /tmnf \
    && rm /tmp/server.zip /tmnf/TrackmaniaServer.exe \
    && chmod +x /tmnf/TrackmaniaServer \
    && adduser -D -H -u 10021 tmnf \
    && mkdir -p /tmnf/Logs /tmnf/GameData/Tracks/Challenges/League \
    # The league's tracks: Nadeo's own stock tracks from the same archive (week 1: A01-Race).
    && cp /tmnf/GameData/Tracks/Campaigns/Nations/White/A01-Race.Challenge.Gbx /tmnf/GameData/Tracks/Challenges/League/ \
    && chown -R tmnf /tmnf

USER tmnf

# 2350 game (tcp+udp), 3450 p2p (tcp+udp), 5000 XML-RPC (GBXRemote 2).
EXPOSE 2350/tcp 2350/udp 3450/tcp 3450/udp 5000/tcp

# The config and the match settings are mounted read-only by the script
# (docker/tmnf/runtime/, gitignored: it holds the SuperAdmin password).
ENTRYPOINT ["/tmnf/TrackmaniaServer", "/nodaemon", "/noautoquit", "/lan", "/dedicated_cfg=league_cfg.txt", "/game_settings=MatchSettings/league.txt"]
