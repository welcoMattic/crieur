# Crieur

**Your reading list, read aloud.** Paste a link, send it from the browser extension, or let your AI assistant send it over MCP: Crieur extracts the article, prepares it for the ear, and reads it with a natural voice, in French or in English, within seconds. Follow along with the highlighted transcript, or subscribe to your library as a private podcast.

Built with Symfony 8.1 and Symfony AI. It runs on your machine in Docker (Phonon voices by Gradium, Ministral 3 by Mistral AI as speech editor), or in the cloud with a [Mistral AI](https://mistral.ai) key.

This repository holds the web app (PHP), the browser extension for Chrome and Firefox (`extension/`), the article extractor ([Defuddle](https://github.com/kepano/defuddle) compiled into one binary, `tools/defuddle/`) and the Docker images (`Dockerfile`, `docker/`).

## How it fits together

```
browser, extension, MCP client
        │
        ▼
 web (Symfony + FrankenPHP) ── SQLite: articles, settings, queue (Messenger)
        │
        ├── worker: extracts the article (Defuddle), then synthesizes the voice
        │           └─ Phonon: Ollama on the Mac, or the Phonon servers of Gradium in Docker
        │              (or Voxtral by Mistral AI, in the cloud)
        └── editor: rewrites the text for the ear
                    └─ Ministral 3 in Docker Model Runner (or Mistral Small, in the cloud)
```

The voices and the editor are reached through the bridges of [Symfony AI](https://symfony.com/doc/current/ai/index.html): Ollama, Mistral, Docker Model Runner.

## Run it on your machine

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Mac, Windows) or Docker Engine with Compose (Linux), and about 15 GB of disk.

1. **Turn on Docker Model Runner** (the speech editor runs there): Docker Desktop, *Settings*, *AI*, *Enable Docker Model Runner*. On Linux with Docker Engine: install the `docker-model-plugin` package.
2. **Get the code:**
   ```sh
   git clone https://github.com/welcoMattic/crieur.git
   cd crieur
   ```
3. **Prepare the voices:**

   | Mac (Apple Silicon) | Linux, Windows |
   |---|---|
   | Install [Ollama](https://ollama.com/download) 0.40.11 or later, then: `ollama pull phonon:40m-multilingual` | Create a free [Hugging Face](https://huggingface.co) account, accept the terms of [phonon-40m-fr](https://huggingface.co/gradium/phonon-40m-fr) and [phonon-40m-en](https://huggingface.co/gradium/phonon-40m-en), and create a token (read access). Copy `.env.docker.dist` to `.env.docker` (`cp`, or `copy` on Windows) and paste the token after `HF_TOKEN=`. |

4. **Start Crieur:**

   | Mac (Apple Silicon) | Linux, Windows |
   |---|---|
   | `docker compose up --detach` | `docker compose --file compose.yaml --file compose.gradium.yaml up --detach` |

   The first start builds Crieur and downloads Ministral 3 (5 GB): allow a few minutes.
5. **Open <http://localhost:8000>** and paste a link.

On Linux and Windows, add the same two `--file` options to every `docker compose` command below.

- **Check the setup:** `docker compose exec web php bin/console crieur:doctor`
- **Logs:** `docker compose logs --follow`
- **Stop:** `docker compose down` (the library stays in a Docker volume)
- **Browser extension:** `docker compose run --rm extension` builds it in `extension/dist/`. In Chrome, open `chrome://extensions`, turn on *Developer mode*, *Load unpacked*, and choose `extension/dist/chrome`. In Firefox, open `about:debugging#/runtime/this-firefox`, *Load Temporary Add-on*, and choose `extension/dist/firefox/manifest.json`. Then set `http://localhost:8000` in its options.
- **In the cloud instead:** with a Mistral AI key in `.env.docker` (`MISTRAL_API_KEY=`), the Settings page offers Voxtral voices and the Mistral Small editor.
- **AI assistants (MCP):** Settings shows how to add Crieur to Claude Code or another MCP client, over HTTP (`/mcp`) or stdio (`php bin/console mcp:server`). Over HTTP, Crieur answers for `localhost` only: add its public host name to `CRIEUR_MCP_HOSTS` to reach it from another machine.

## Work on the code

Without Docker for the app: PHP 8.4.1 or later (with `intl` and `pdo_sqlite`), [Composer](https://getcomposer.org), the [Symfony CLI](https://symfony.com/download), [Castor](https://castor.jolicode.com), [Bun](https://bun.sh) 1.3.2 and Node.js 22 or later; Ollama and Docker Model Runner as above.

```sh
castor install        # PHP dependencies, article extractor, database, browser extension
castor models         # Phonon in Ollama, Ministral 3 in Docker Model Runner
castor doctor --live  # says what is missing and how to fix it
castor start          # http://127.0.0.1:8000, with its queue workers
castor test           # PHPUnit, PHPStan, the tests of the extension
```

`castor list` shows the other tasks.

**Public demo:** `APP_ENV=demo` runs Crieur as on <https://crieur.cleverapps.io>. Each visitor (IP address) may add 5 articles an hour, everyone together 30, of up to 25,000 characters each (parameters `crieur.demo.*` of `config/packages/crieur.yaml`). Its schedule (Symfony Scheduler, `messenger:consume scheduler_demo`, run by the all-in-one image) empties the library every six hours from midnight UTC, and the home page says when; `crieur:demo:reset` empties it at once.

## License

MIT, see [LICENSE](LICENSE). The Phonon 40m voices are licensed CC BY-NC 4.0: personal and non-commercial use only.
