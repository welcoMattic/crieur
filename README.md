# Crieur

**Your reading list, read aloud.** Paste a link, send it from the browser extension, or let your AI assistant send it over MCP: Crieur extracts the article, prepares it for the ear, and reads it with a natural voice, in French or in English, within seconds. Follow along with the highlighted transcript, or subscribe to your library as a private podcast.

Built with Symfony 8.1 and Symfony AI. It runs on your machine with [Ollama](https://ollama.com) (Phonon voices, Ministral as speech editor), or in the cloud with a [Mistral AI](https://mistral.ai) key.

This repository holds the web app (PHP), the browser extension for Chrome and Firefox (`extension/`), the article extractor ([Defuddle](https://github.com/kepano/defuddle) compiled into one binary, `tools/defuddle/`) and the Docker images (`Dockerfile`, `docker/`).

## Getting started

Requirements: PHP 8.4.1 or later (with `intl` and `pdo_sqlite`), [Composer](https://getcomposer.org), the [Symfony CLI](https://symfony.com/download), [Castor](https://castor.jolicode.com), [Bun](https://bun.sh) 1.3.2, Node.js 22 or later, optionally [ffmpeg](https://ffmpeg.org) (podcast episodes in AAC rather than WAV), and for the voices **one** of:

- **Local:** [Ollama](https://ollama.com) 0.40.11 or later on an Apple Silicon Mac;
- **Cloud:** a Mistral AI API key.

```sh
git clone https://github.com/welcoMattic/crieur.git && cd crieur
castor install        # PHP dependencies, article extractor, database, browser extension
```

Local voices:

```sh
castor models         # pulls phonon:40m-multilingual and ministral-3:8b in Ollama
echo 'CRIEUR_EDITOR=ollama' >> .env.local
```

Or cloud voices:

```sh
echo 'MISTRAL_API_KEY=your-key' >> .env.local
echo 'CRIEUR_VOICE_ENGINE=voxtral' >> .env.local
```

Then check and start:

```sh
castor doctor --live  # says what is missing and how to fix it
castor start          # http://127.0.0.1:8000, with its queue workers
```

Open <http://127.0.0.1:8000> and paste a link. `castor list` shows the other tasks (tests, logs, Docker).

**Browser extension:** `castor install` builds it in `extension/dist/`. In Chrome, open `chrome://extensions`, turn on *Developer mode*, *Load unpacked*, and choose `extension/dist/chrome`. In Firefox, open `about:debugging#/runtime/this-firefox`, *Load Temporary Add-on*, and choose `extension/dist/firefox/manifest.json`. Then set the address of your Crieur in its options.

**With Docker:** `castor docker:up` serves Crieur on <http://localhost:8000>, with Phonon through the Ollama of the host (Apple Silicon), or Voxtral with `MISTRAL_API_KEY` and `CRIEUR_VOICE_ENGINE=voxtral` in `.env.docker`. `castor docker:build-server` builds the all-in-one image for hosts without Ollama (Phonon servers of Gradium inside, as on the live demo; it listens on port 8080 and needs a Hugging Face `HF_TOKEN` with access to the Phonon models).

## License

MIT, see [LICENSE](LICENSE). The Phonon 40m voices are licensed CC BY-NC 4.0: personal and non-commercial use only.
