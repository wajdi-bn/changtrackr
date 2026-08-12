FROM wiremock/wiremock:3.13.1@sha256:d61e7720f89483fdef5366843b58d1dfd06bcce5828179c9f2f54de5c28354b0

COPY infra/payment-simulator/mappings /opt/chargetrackr/templates
COPY deployment/production/payment-simulator/entrypoint.sh /usr/local/bin/chargetrackr-wiremock
RUN chmod 755 /usr/local/bin/chargetrackr-wiremock

ENTRYPOINT ["chargetrackr-wiremock"]
