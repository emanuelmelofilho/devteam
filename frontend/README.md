Frontend React app for Kanban module.

Install and run:

```bash
cd frontend
npm install
npm start
```

When using the project Docker Compose, the frontend is available at
http://localhost:3001 (`3001` on the host maps to `3000` in the container).

The app expects `REACT_APP_API_URL` to point to the Laravel API root (e.g., http://localhost:8001/api with Docker Compose).
