FROM alpine:3.22@sha256:14358309a308569c32bdc37e2e0e9694be33a9d99e68afb0f5ff33cc1f695dce

COPY deployment/production/compose.yml /bundle/compose.yml
COPY deployment/production/Caddyfile /bundle/Caddyfile
COPY deployment/production/scripts /bundle/scripts
COPY deployment/production/systemd /bundle/systemd
