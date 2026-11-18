# Privacy policy of the Crieur browser extension

Last updated: 18 November 2026. Applies to the Crieur extension for Chrome and Firefox.

The extension sends the article you are reading to **your own Crieur server**, which reads it
aloud. It has no server of its own: its developer receives nothing, and no third party does.

## What the extension reads, and when

Only when you ask for it: the toolbar button (or its keyboard shortcut) followed by *Read aloud*,
or the page menu *Listen to this article with Crieur*. It then reads the page of the current tab,
as your browser shows it, and takes out of it:

- the text of the article and its title;
- the address of the page;
- when the page gives them: the site name, the author, the language, the summary, the address of
  its main image and the publication date.

It reads nothing on other pages or tabs, nothing in the background, never your browsing history,
your cookies, your passwords or your form data.

## Where it goes

To the Crieur server whose address you entered in the options of the extension, and nowhere else.
The request carries the article, the voice and the language you chose, and the API token of that
server if you entered one. It carries no cookie. There is no analytics, no advertising, no tracking
of any kind.

What happens next depends on your Crieur server, which you run or choose: it keeps the article in
your library to read it aloud, until you delete it. To synthesize the voice, it uses the engines it
is configured with, on the same machine (Ollama) or with a cloud provider (Mistral AI), under that
provider's own terms.

## What the extension keeps in your browser

The address of your Crieur server, its API token and your last choice of voice and language, in
the extension storage of this browser. They never leave it except to reach that server, and they are
removed when you remove the extension.

## Permissions

- **Active tab and scripting:** read the article of the current tab, only when you ask.
- **Storage:** keep the settings above.
- **Context menus:** the page menu item.
- **Access to your Crieur server:** the only site the extension asks to reach, requested when you
  save its address.

## Changes and contact

Changes to this policy are published with the source code of Crieur, with their date. Questions:
open an issue on the repository of Crieur.
