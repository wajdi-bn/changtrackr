FROM node:24-alpine@sha256:d32cdf619f63fe0471182d08996dd516c6275bb5fd31ae06e55a570bd9e1ad43 AS builder

ARG SAP_SIMULATOR_REF

RUN apk add --no-cache build-base git python3 \
    && npm install --global --ignore-scripts corepack \
    && corepack enable

WORKDIR /source
RUN git init \
    && git remote add origin https://github.com/SAP/e-mobility-charging-stations-simulator.git \
    && git fetch --depth 1 origin "${SAP_SIMULATOR_REF}" \
    && git checkout --detach FETCH_HEAD

RUN --mount=type=cache,id=chargetrackr-sap-pnpm,target=/pnpm/store \
    pnpm config set store-dir /pnpm/store \
    && pnpm config set fetch-retries 5 \
    && pnpm config set fetch-retry-mintimeout 20000 \
    && pnpm config set fetch-retry-maxtimeout 120000 \
    && pnpm config set fetch-timeout 600000 \
    && pnpm install --ignore-scripts --frozen-lockfile --network-concurrency=8

RUN pnpm build && pnpm --filter cli build

FROM node:24-alpine@sha256:d32cdf619f63fe0471182d08996dd516c6275bb5fd31ae06e55a570bd9e1ad43 AS simulator

WORKDIR /usr/app
COPY --from=builder /source/node_modules ./node_modules
COPY --from=builder /source/dist ./dist
COPY --from=builder /source/package.json ./package.json
COPY infra/ocpp/simulator/config.json ./dist/assets/config.json
COPY infra/ocpp/simulator/chargetrackr-idtags.json ./dist/assets/chargetrackr-idtags.json
COPY infra/ocpp/simulator/chargetrackr.station-template.json ./dist/assets/station-templates/chargetrackr.station-template.json
COPY infra/ocpp/simulator/stations.json ./dist/assets/chargetrackr-stations.json
COPY infra/ocpp/simulator/generate-simulator-config.mjs /usr/local/lib/chargetrackr/generate-simulator-config.mjs
COPY infra/ocpp/simulator/configure-and-run.sh /usr/local/bin/configure-and-run
RUN chmod +x /usr/local/bin/configure-and-run

EXPOSE 8080
ENTRYPOINT ["configure-and-run"]

FROM node:24-alpine@sha256:d32cdf619f63fe0471182d08996dd516c6275bb5fd31ae06e55a570bd9e1ad43 AS cli

WORKDIR /usr/app
COPY --from=builder /source/ui/cli/dist ./cli
COPY infra/ocpp/simulator/cli-config-template.json ./cli-config-template.json
COPY infra/ocpp/simulator/read-station-state.mjs ./read-station-state.mjs
COPY infra/ocpp/simulator/run-scenario.sh /usr/local/bin/run-ocpp-scenario
COPY infra/ocpp/simulator/run-transaction-scenario.sh /usr/local/bin/run-ocpp-transaction-scenario
COPY infra/ocpp/simulator/run-connector-status.sh /usr/local/bin/run-ocpp-connector-status
COPY infra/ocpp/simulator/run-connection-state.sh /usr/local/bin/run-ocpp-connection-state
COPY infra/ocpp/simulator/run-stop-transaction.sh /usr/local/bin/run-ocpp-stop-transaction
RUN chmod +x /usr/local/bin/run-ocpp-scenario /usr/local/bin/run-ocpp-transaction-scenario /usr/local/bin/run-ocpp-connector-status /usr/local/bin/run-ocpp-connection-state /usr/local/bin/run-ocpp-stop-transaction

ENTRYPOINT ["run-ocpp-scenario"]
