# WhatsApp bot + PDF service for SADAR-SPKD.
FROM node:22-bookworm-slim

# Debian's Chromium (with fonts) is used for PDF rendering instead of Puppeteer's download.
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium fonts-liberation fonts-noto-color-emoji ca-certificates \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000

WORKDIR /app

COPY package.json ./
RUN npm install --include=dev --no-audit --no-fund

COPY tsconfig.json ./
COPY src ./src
RUN npm run build && npm prune --omit=dev

# WhatsApp session (keep on a volume so a restart does not ask for a new QR).
VOLUME ["/app/auth"]

EXPOSE 3000
CMD ["node", "dist/server.js"]
