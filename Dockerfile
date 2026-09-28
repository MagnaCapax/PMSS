FROM debian:12-slim

# Keep the image small and deterministic: this container is only for
# repository-local validation, documentation work, and dry-run exploration.
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        file \
        git \
        php-cli \
        php-xml \
        ripgrep \
        shellcheck \
        shfmt \
    && rm -rf /var/lib/apt/lists/*

# php-xml and curl are not optional extras: the hermetic development suite needs
# DOMDocument (welcome announcements, managed D-Bus policy renders) and the
# filemanager remote-URL contract test reaches its guard only when curl exists.
# Without them the documented `scripts/testing/test-all.sh` cannot pass in the
# documented container.

# PMSS is mounted in at runtime so the image stays generic.
WORKDIR /workspace

CMD ["bash"]
