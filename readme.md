This is the repository for a Final Year Project called ChatSearch.

## Setup

Prerequisites: Docker and Docker Compose.

**1. Clone and configure environment**

```bash
git clone https://github.com/thelonedigger/FYP-ChatSearch && cd FYP-ChatSearch
cp backend/.env.example backend/.env
```

Edit `backend/.env` and set your API keys and host IP:

- `JINA_API_KEY` — required if using Jina for embeddings/reranking (default provider).
- `OPENAI_API_KEY` — required if using the cloud LLM profile.
- `CORS_ALLOWED_ORIGINS` — replace `10.1.7.56` with your machine's LAN IP or use `http://localhost:5173`.

In `docker-compose.yml`, update the same IP in the `backend` and `frontend` environment sections to match, or switch both to `localhost`.

**2. Start the containers**

```bash
docker compose up -d --build
```

This brings up four services: `postgres` (pgvector), `backend` (Laravel), `frontend` (React/Vite), and `ollama`.

**3. Generate the app key**

```bash
docker exec backend php artisan key:generate
```

**4. Run migrations and seed dev users**

```bash
docker exec backend php artisan migrate
docker exec backend php artisan db:seed
```

This creates four dev users (User A–D) with passwordless login.

**5. Pull Ollama models (only if using local providers)**

If your `.env` uses Ollama-backed providers (`EMBEDDING_PROVIDER=ollama`, etc.), pull the required models:

```bash
docker exec ollama ollama pull gemma3:12b
docker exec ollama ollama pull phi4-mini:3.8b
docker exec ollama ollama pull harrier-oss-v1-0.6b
docker exec ollama ollama pull Qwen3-Reranker-0.6B
```

Skip this step entirely if you're using only Jina + OpenAI (the defaults).

**6. Access the app**

- Frontend: `http://localhost:5173`
- Backend API: `http://localhost:8000/api/v1`
- Ollama: `http://localhost:11434`
- Postgres: `localhost:5433` (user: `postgresql`, password: `postgresql_password`, db: `postgresql_vector_db`)

**7. Process documents**

Upload via the admin UI, or from the CLI:

```bash
# Place files in backend/storage/app/documents, then:
docker exec backend php artisan documents:process-async --sync

# Or process a specific file:
docker exec backend php artisan documents:process-async /var/www/storage/app/documents/myfile.pdf --sync
```

Drop `--sync` to queue jobs instead, then run a worker:

```bash
docker exec backend php artisan queue:work --queue=document-processing
```