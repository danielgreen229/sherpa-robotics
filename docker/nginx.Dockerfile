FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM nginx:1.28-alpine
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /build/dist/catalog/browser /usr/share/nginx/html
COPY backend/openapi.json /usr/share/nginx/html/openapi.json
EXPOSE 80
