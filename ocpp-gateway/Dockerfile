FROM python:3.13-slim@sha256:9662417aace5ae7b8e2609cce472b72a8958e134ba372808abe9cc1a0c0125e6 AS runtime

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN useradd --create-home --uid 10001 gateway

WORKDIR /app
COPY pyproject.toml README.md ./
COPY src ./src
RUN pip install --no-cache-dir . \
    && chown -R gateway:gateway /app

USER gateway
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD ["python", "-m", "chargetrackr_ocpp.healthcheck"]
CMD ["chargetrackr-ocpp"]

FROM runtime AS test
USER root
COPY --chown=gateway:gateway tests ./tests
RUN pip install --no-cache-dir ".[test]"
USER gateway
CMD ["pytest", "-q"]
