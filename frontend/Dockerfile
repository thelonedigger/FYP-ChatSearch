FROM node:20-alpine

WORKDIR /app

# Change ownership of the working directory to the node user
RUN chown -R node:node /app

# Switch to the non-root node user
USER node

COPY --chown=node:node package*.json ./

RUN npm install

COPY --chown=node:node . .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]