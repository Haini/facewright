#############################
# Stage 1: Builder
#############################
FROM python:3.12-slim-bookworm AS builder

# insightface has Cython extensions compiled at install time, hence build-essential/cmake.
RUN apt-get update && apt-get install -y curl build-essential cmake && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

COPY requirements.txt /
RUN --mount=type=cache,target=/root/.cache/pip \
    python3 -m pip install --root-user-action=ignore -r requirements.txt && rm requirements.txt

# Download and install FRP client with checksum verification
ARG FRP_VERSION=0.61.1
ARG FRP_AMD64_SHA256=bff260b68ca7b1461182a46c4f34e9709ba32764eed30a15dd94ac97f50a2c40
ARG FRP_ARM64_SHA256=af6366f2b43920ebfe6235dba6060770399ed1fb18601e5818552bd46a7621f8

RUN set -ex; \
    ARCH=$(uname -m); \
    if [ "$ARCH" = "aarch64" ]; then \
        FRP_ARCH="arm64"; \
        FRP_SHA256="${FRP_ARM64_SHA256}"; \
    else \
        FRP_ARCH="amd64"; \
        FRP_SHA256="${FRP_AMD64_SHA256}"; \
    fi; \
    FRP_URL="https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${FRP_ARCH}.tar.gz"; \
    curl -fsSL "${FRP_URL}" -o /tmp/frp.tar.gz; \
    ACTUAL_SHA256=$(sha256sum /tmp/frp.tar.gz | cut -d' ' -f1); \
    if [ "$ACTUAL_SHA256" != "$FRP_SHA256" ]; then \
        echo "Checksum verification failed for FRP v${FRP_VERSION} (${FRP_ARCH})"; \
        exit 1; \
    fi; \
    tar -C /tmp -xzf /tmp/frp.tar.gz; \
    cp /tmp/frp_${FRP_VERSION}_linux_${FRP_ARCH}/frpc /usr/local/bin/frpc; \
    chmod +x /usr/local/bin/frpc; \
    rm -rf /tmp/frp_${FRP_VERSION}_linux_${FRP_ARCH} /tmp/frp.tar.gz

#############################
# Stage 2: Final Runtime Image
#############################
FROM python:3.12-slim-bookworm

COPY --from=builder /usr/local/ /usr/local/

# libgl1/libglib2.0-0 cover the shared libs opencv-python-headless still dlopens on some distros.
RUN apt-get update && apt-get install -y curl procps libgl1 libglib2.0-0 && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

ADD /ex_app/cs[s] /ex_app/css
ADD /ex_app/im[g] /ex_app/img
ADD /ex_app/j[s] /ex_app/js
ADD /ex_app/l10[n] /ex_app/l10n
ADD /ex_app/li[b] /ex_app/lib

COPY --chmod=775 healthcheck.sh /
COPY --chmod=775 start.sh /

# NumPy's BLAS would otherwise start one thread per core for the /cluster matrix products. ONNX Runtime doing the same
# correlated with native crashes and host reboots on this hardware (see the top of ex_app/lib/main.py).
ENV OMP_NUM_THREADS=2 OPENBLAS_NUM_THREADS=2 MKL_NUM_THREADS=2
# The embedding cache (ex_app/lib/clustering.py). Mount a volume here, or every restart empties it.
ENV FACEWRIGHT_DATA_DIR=/data

WORKDIR /ex_app/lib
ENTRYPOINT ["/start.sh", "python3", "main.py"]
HEALTHCHECK --interval=2s --timeout=2s --retries=300 CMD /healthcheck.sh
