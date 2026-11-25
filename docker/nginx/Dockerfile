FROM nginxinc/nginx-unprivileged:1.27-alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --chown=nginx:nginx public /var/www/html/public

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1
