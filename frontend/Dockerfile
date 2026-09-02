FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json ./
# The current lock file contains a peer dependency version newer than the one
# accepted by react-scripts 5. npm install reconciles it during the image build.
RUN npm install --legacy-peer-deps

COPY . .

EXPOSE 3000

CMD ["npm", "start"]
