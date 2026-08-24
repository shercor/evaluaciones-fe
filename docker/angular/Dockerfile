# ---------------------------------------------------------------
# dev — servidor de desarrollo con recarga en vivo.
# Es el target que usa docker-compose.
# ---------------------------------------------------------------
FROM node:22-alpine AS dev

WORKDIR /app
ENV NG_CLI_ANALYTICS=false

# node_modules vive en un volumen nombrado y el código entra por bind mount
# desde ./frontend, así que no se copia nada.
#
# El directorio se crea aquí a propósito: al montar un volumen vacío, Docker
# copia contenido Y dueño desde la ruta de la imagen. Sin esto el volumen
# nace como root y npm, que corre como `node`, no puede escribir en él.
RUN mkdir -p /app/node_modules && chown -R node:node /app

USER node

EXPOSE 4200
CMD ["npm", "start", "--", "--host", "0.0.0.0", "--port", "4200"]


# ---------------------------------------------------------------
# build — compila el bundle de producción.
# ---------------------------------------------------------------
FROM node:22-alpine AS build

WORKDIR /app
ENV NG_CLI_ANALYTICS=false

# package*.json primero: la capa de dependencias se reaprovecha
# mientras no cambien, aunque cambie el código.
COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build


# ---------------------------------------------------------------
# prod — nginx sirviendo el bundle estático.
# ---------------------------------------------------------------
FROM nginx:1.27-alpine AS prod

COPY --from=build /app/dist/frontend/browser /usr/share/nginx/html
COPY docker/angular/nginx-spa.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
