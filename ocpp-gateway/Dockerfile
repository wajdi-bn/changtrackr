FROM python:3.13-slim AS runtime

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
CMD ["chargetrackr-ocpp"]

FROM runtime AS test
USER root
COPY --chown=gateway:gateway tests ./tests
RUN pip install --no-cache-dir ".[test]"
USER gateway
CMD ["pytest", "-q"]
