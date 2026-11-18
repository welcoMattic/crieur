# Publishing the extension: Chrome Web Store and addons.mozilla.org

Everything below is ready to paste. Nothing has been submitted.

## Before submitting

| Item | Status |
|---|---|
| Packages | `npm run build` writes `dist/crieur-chrome-1.0.0.zip` and `dist/crieur-firefox-1.0.0.zip` (the version comes from `src/manifest.json`). |
| Images | `store/`: icon 128 x 128, screenshots 1280 x 800 in English (`screenshot-en-*`) and French (`screenshot-fr-*`), small promo tile 440 x 280. |
| Checks | `npm test`, `npm run lint` (0 error), `npm run e2e` (Chromium, the test build allowed on 127.0.0.1), `npm run e2e:firefox` (the AMO zip in Firefox, options page, permission, article sent to a plain http server). |
| Try by hand | **To do.** The toolbar click that grants `activeTab` cannot be automated: load `dist/chrome` and `dist/firefox` once and send one article from the button. Firefox for Android has never been tried. |
| Developer accounts | **To do.** Chrome Web Store: one-time registration fee, 2-step verification. AMO: a Firefox account, free. |
| Privacy policy URL | **To do.** [PRIVACY.md](PRIVACY.md) once the repository is public, for instance `https://github.com/welcoMattic/crieur/blob/main/extension/PRIVACY.md`. |
| A Crieur for the reviewers | `https://crieur.cleverapps.io` (Clever Cloud, `CRIEUR_API_TOKEN` set): put its address and token in the test notes below. Its library starts again empty at each restart. |
| `homepage_url` | `https://github.com/welcoMattic/crieur` in `src/manifest.json`: change it if the repository lives elsewhere. |
| Add-on id (Firefox) | `{6f1c4d1e-3b7a-4a49-9c55-2f6e8f0b9d21}` in `build.mjs`: never change it after the first upload. |

**Wording.** Both stores refuse extensions that get around paywalls or logins, and Crieur does not:
it reads the page the browser already shows, with the reader's own access. Keep that wording
("articles you have access to", "your subscriptions") and never write "paywall", "bypass" or
"unlock" in the listings.

## Listing copy

### Name and summary (from the manifest, translated)

| | English | French |
|---|---|---|
| Name | Crieur: listen to this article | Crieur : écouter cet article |
| Summary (Chrome, 132 max) | Listen to the article you are reading, with your own Crieur. It reads the page your browser shows you, including your subscriptions. | Écoutez l’article que vous lisez, avec votre Crieur. Il lit la page que votre navigateur vous montre, abonnements compris. |
| Summary (AMO, 250 max) | Listen to the article you are reading with your own Crieur server: the extension reads the page as your browser shows it, subscriptions included, and Crieur reads it aloud with a natural voice. | Écoutez l’article que vous lisez avec votre serveur Crieur : l’extension lit la page telle que votre navigateur vous la montre, abonnements compris, et Crieur la lit à voix haute avec une voix naturelle. |

### Description (English)

```text
Crieur reads aloud the article you are reading, with a natural voice, in English or in French.

Click the Crieur button on an article, choose a voice, and listen within seconds. Follow along with the highlighted transcript, change the speed, or listen later from your library or as a private podcast.

The extension works with your own Crieur, the open source app you run on your computer or on your server. It reads the page as your browser shows it to you, with Defuddle, the extraction engine of Obsidian Web Clipper: the articles of the sites you subscribe to or log in to are read like any other page, with your own access.

Private by design
• The page is read only when you ask: the toolbar button, its shortcut (Alt+Shift+L) or the page menu.
• The article and its address go to your Crieur server, and nowhere else. No cookie, no access to your browsing history, no analytics, no account.
• The only site the extension asks to reach is your Crieur server.

What you need
• A Crieur server: on your computer with Ollama, or in the cloud with a Mistral AI key. Enter its address, and its API token if it has one, in the options of the extension.

Crieur is open source, under the MIT license.
```

### Description (French)

```text
Crieur lit à voix haute l’article que vous lisez, avec une voix naturelle, en français ou en anglais.

Cliquez sur le bouton Crieur sur un article, choisissez une voix, et écoutez en quelques secondes. Suivez la transcription surlignée, changez la vitesse, ou écoutez plus tard depuis votre bibliothèque ou en podcast privé.

L’extension fonctionne avec votre propre Crieur, l’application libre que vous installez sur votre ordinateur ou sur votre serveur. Elle lit la page telle que votre navigateur vous la montre, avec Defuddle, le moteur d’extraction d’Obsidian Web Clipper : les articles des sites auxquels vous êtes abonné ou connecté sont lus comme n’importe quelle page, avec vos propres accès.

Respect de la vie privée
• La page n’est lue que lorsque vous le demandez : le bouton de la barre d’outils, son raccourci (Alt+Maj+L) ou le menu de la page.
• L’article et son adresse partent vers votre serveur Crieur, et nulle part ailleurs. Aucun cookie, aucun accès à votre historique, aucune mesure d’audience, aucun compte.
• Le seul site que l’extension demande à joindre est votre serveur Crieur.

Ce qu’il vous faut
• Un serveur Crieur : sur votre ordinateur avec Ollama, ou dans le cloud avec une clé Mistral AI. Saisissez son adresse, et son jeton d’API s’il en a un, dans les options de l’extension.

Crieur est un logiciel libre, sous licence MIT.
```

## Chrome Web Store (developer dashboard)

**Package:** upload `dist/crieur-chrome-1.0.0.zip`.

**Store listing**
- Category: Accessibility (or Productivity, Tools). Language: English, then add French as a localized listing.
- Store icon: `store/icon-store-128.png`. Screenshots: `store/screenshot-en-1-popup.png` to `-5-options.png` (French listing: `screenshot-fr-*`). Small promo tile: `store/promo-small-440x280.png`. Marquee: none (optional).
- Official URL / homepage: the repository. Support URL: its issues page.

**Privacy practices**
- Single purpose: *Sends the article of the current tab, on the reader's request, to the reader's own Crieur server, which reads it aloud.*
- Permission justifications:

| Permission | Justification |
|---|---|
| `activeTab` | Reads the article of the current tab only when the reader clicks the toolbar button, uses its shortcut or the page menu. |
| `scripting` | Runs the article extraction (Defuddle, bundled in the package) in that tab, at that moment only. |
| `storage` | Keeps the address of the reader's Crieur server, its API token and the last voice and language chosen, on this device. |
| `contextMenus` | Adds "Listen to this article with Crieur" to the page menu. |
| Host permissions (optional) | Crieur is self-hosted: the reader chooses the server address. The options page requests the origin of that server only, at the moment it is saved, to send the article to it. No other host is ever requested. |

- Remote code: **No.** All the code is in the package, Defuddle included.
- Data usage: check **Website content** (the text of the article) and **Web history** (the address and title of the page sent). They go to the reader's own Crieur server only. The API token is a credential of that same server, typed by the reader and sent only to it.
- Certify the three statements (not sold to third parties, not used for purposes unrelated to the single purpose, not used for creditworthiness or lending).
- Privacy policy: the public URL of [PRIVACY.md](PRIVACY.md).

**Test instructions** (field for the reviewers)

```text
Crieur is a self-hosted text-to-speech app: the extension needs a Crieur server.
Test server: <URL>   API token: <TOKEN>

1. Open the options of the extension, enter the server address and the token, click "Save and test". The browser asks for the permission to reach this server; accept. The page shows "Connected to Crieur".
2. Open an article, for instance https://en.wikipedia.org/wiki/Town_crier
3. Click the Crieur button in the toolbar, then "Read aloud". The article opens on the test server and starts playing within seconds.
Also: right-click the page, "Listen to this article with Crieur".
```

**Distribution:** public, all regions (or unlisted first, to try the store version).

## addons.mozilla.org (Developer Hub)

**Upload:** *Submit a New Add-on*, *On this site*, upload `dist/crieur-firefox-1.0.0.zip`. Platforms: Firefox (minimum version 140, the first with the built-in data consent). Leave Firefox for Android unchecked until it has been tried on a phone (the manifest already sets 142 for it).

**Source code:** not needed. The code is neither minified nor transpiled: `build.mjs` copies `src/` and writes the manifest. Answer "No" and explain in the notes (below).

**Listing**
- Name, summary, description: the copy above (English default, add French).
- Categories: Feeds, News & Blogging; Other. Tags: text to speech, read aloud, accessibility.
- Support: the repository and its issues page. License: MIT.
- Privacy policy: paste [PRIVACY.md](PRIVACY.md).
- Screenshots: the same `store/screenshot-*` files (1280 x 800).

**Data collection:** declared in the manifest (`browser_specific_settings.gecko.data_collection_permissions`): `websiteContent` and `browsingActivity`, required. Firefox shows them in its install prompt.

**Notes to reviewer**

```text
Crieur is a self-hosted text-to-speech app: the extension sends the article of the current tab, on the reader's request, to the reader's own Crieur server.
Test server: <URL>   API token: <TOKEN>   (Options page, then "Save and test" and accept the permission request.)
Then open https://en.wikipedia.org/wiki/Town_crier, click the toolbar button, "Read aloud".

No build step: the files are the source. build.mjs only copies src/ and writes the manifest per browser.
Third-party library: vendor/defuddle.js is the unmodified dist/index.js of defuddle 0.19.6 (MIT, https://github.com/kepano/defuddle)
  https://registry.npmjs.org/defuddle/-/defuddle-0.19.6.tgz (package/dist/index.js)
  SHA-256 f40075dc7b7b0460c16f77b9486a0ee66907a19677d2984e198e94356116a1e8
The innerHTML warnings of web-ext lint are in that library.
content_security_policy.extension_pages only drops the upgrade-insecure-requests of the default policy: Crieur is self-hosted, often on plain http on the home network.
```

## After publication

- Each new version: raise `version` in `src/manifest.json`, `npm run build`, upload both zips.
- The published extensions get their store ids; the server accepts requests from any extension and refuses other web pages, so nothing changes on the Crieur side. The API token remains the protection of a server reachable by others.
