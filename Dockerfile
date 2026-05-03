FROM node:22-alpine

WORKDIR /app

COPY package.json ./
COPY server.mjs ./
COPY public ./public
COPY data/.gitkeep ./data/.gitkeep
COPY README.md ./

RUN mkdir -p /app/data

ENV NODE_ENV=production
ENV PORT=3000

EXPOSE 3000

CMD ["node", "server.mjs"]
