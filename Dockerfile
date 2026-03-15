FROM debian:12-slim

# Keep the image small and deterministic: this container is only for
# repository-local validation, documentation work, and dry-run exploration.
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        file \
        git \
        php-cli \
        ripgrep \
        shellcheck \
        shfmt \
    && rm -rf /var/lib/apt/lists/*

# PMSS is mounted in at runtime so the image stays generic.
WORKDIR /workspace

CMD ["bash"]
