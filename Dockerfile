FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends nginx libnginx-mod-rtmp ffmpeg \
    && rm -rf /var/lib/apt/lists/*
COPY scripts/convert-recording.sh /usr/local/bin/convert-recording
RUN chmod 755 /usr/local/bin/convert-recording
EXPOSE 80 1935
CMD ["nginx", "-g", "daemon off;"]
