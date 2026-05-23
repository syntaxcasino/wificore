# Use latest nginx alpine-slim for minimal size
FROM alpine:3.23
RUN apk add --no-cache nginx nginx-mod-http-brotli \
 && rm -f /etc/nginx/http.d/default.conf \
 && rm -rf /usr/share/nginx/html/* \
 && ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log \
 && mkdir -p /var/run/nginx
COPY nginx.conf /etc/nginx/http.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]