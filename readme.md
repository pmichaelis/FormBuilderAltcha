# FormBuilderAltcha

## ProcessWire - FormBuilder ALTCHA

Spam protection for [ProcessWire](https://processwire.com) [FormBuilder](https://processwire.com/store/form-builder/) forms using [ALTCHA](https://altcha.org).

Before a form is submitted, the visitor's browser solves a small proof-of-work challenge in the background. The server only accepts submissions with a valid, unused solution. Bots that post forms directly or do not run JavaScript are rejected.

- Runs entirely on your own server: no external service, no API key, no cookies
- No image puzzles or "click all traffic lights" for your visitors
- Enabled per form in the FormBuilder form editor
- Translatable, German translation included

For checking the content of submissions (blacklists, disposable email addresses, links), see the separate module [FormBuilderBlacklist](https://github.com/pmichaelis/FormBuilderBlacklist). Both modules can be used together.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Enabling ALTCHA for a form](#enabling-altcha-for-a-form)
- [Settings](#settings)
- [What visitors see](#what-visitors-see)
- [Logs](#logs)
- [Testing](#testing)
- [Translations](#translations)
- [Troubleshooting](#troubleshooting)
- [Uninstalling](#uninstalling)
- [Updating dependencies](#updating-dependencies)
- [License](#license)

## Requirements

- PHP 8.1 or later
- ProcessWire 3.0.200 or later
- FormBuilder (commercial module by Ryan Cramer)
- HTTPS on the frontend. The widget uses the browser's Web Crypto API, which is only available in a secure context. `localhost` and local development domains with a valid certificate work as well.

## Installation

All dependencies are included in the `vendor/` directory, so Composer is not required.

### Option 1: Install from URL in the admin

1. In the ProcessWire admin, go to **Modules > New**.
2. Under **Add Module From URL**, enter:
   `https://github.com/pmichaelis/FormBuilderAltcha/archive/HEAD.zip`
3. Click **Get**, then **Install Now**.

### Option 2: Install manually

1. Download the repository as ZIP or clone it:
   `git clone https://github.com/pmichaelis/FormBuilderAltcha.git`
2. Place the folder in `site/modules/` so that the module file is located at
   `site/modules/FormBuilderAltcha/FormBuilderAltcha.module.php`.
3. In the admin, go to **Modules > Refresh**.
4. Find **FormBuilder ALTCHA** in the list (section FormBuilder or Site) and click **Install**.

After installation, the module does nothing until ALTCHA is enabled for a form.

## Enabling ALTCHA for a form

ALTCHA is configured per form in the FormBuilder form editor.

1. Go to **Setup > Forms** and edit the form.
2. Open the tab **Actions**.
3. Under **Select one or more form actions**, check **Filter spam**. ALTCHA only runs when this action is enabled.
4. Open the section **Filter spam**. At its end you will find the fieldset **ALTCHA**.
5. Check **Enable ALTCHA** and adjust the settings if needed.
6. Click **Save**.

The ALTCHA widget now appears above the submit button of this form. Repeat these steps for every form that should be protected.

## Settings

All settings are located in the fieldset **ALTCHA** in the section **Filter spam** of the form's **Actions** tab.

| Setting | Description | Default |
| --- | --- | --- |
| Enable ALTCHA | Protects submissions of this form with ALTCHA. | off |
| Difficulty | How much computing work the browser has to do: Low (approx. 0.2 s), Medium (approx. 0.5 s), High (approx. 2 s). Times refer to a current computer; older smartphones take about 3 to 5 times longer. | Medium |
| Display | **Checkbox above the submit button**: the widget is visible and starts computing as soon as the visitor focuses a form field, so it is usually done before the form is filled in. **Popup on submit**: the widget stays hidden and computes only when the visitor clicks submit; a small popup shows the progress. | Checkbox |
| Widget language | ISO language code of the widget texts, e.g. `de` or `en`. Leave blank to use the language of the current page (see [Translations](#translations)). | blank |
| Log failed checks | Writes the form name and the reason of each failed check to the log `form-builder-altcha`. | on |
| Simulate failure (test mode) | Simulates a failed check for testing, see [Testing](#testing). | Off |

### Interaction with the FormBuilder spam settings

The section **Filter spam** also contains FormBuilder's own setting **What should happen when spam is detected?** (silent discard, error message, or save as spam entry). This setting does not apply to ALTCHA:

- If the ALTCHA check fails, the submitter always gets an error message and the form is shown again, so that real visitors (for example with an expired challenge) can simply submit again.
- No entry is saved and no emails are sent for a failed check.

ALTCHA runs after FormBuilder's own spam filters (honeypot, spam trigger words, Turing test, Akismet). If one of those already detected spam, ALTCHA is not checked.

## What visitors see

With the display **Checkbox above the submit button**, the widget shows a checkbox labeled "I'm not a robot" (in the widget language). It is checked automatically once the challenge has been solved; visitors can also click it themselves.

With the display **Popup on submit**, nothing is visible until the visitor submits the form. A small popup then shows the progress, and the form is submitted automatically once the challenge has been solved.

If the check fails on the server, the form is shown again with the message:

> The spam check failed. Please confirm the verification above the submit button and submit the form again.

## Logs

When **Log failed checks** is enabled, every failed check is written to **Setup > Logs > form-builder-altcha**. Each entry contains the form name and the reason:

| Log message | Meaning |
| --- | --- |
| ALTCHA: solution missing | The submission contained no solution, e.g. a bot posting the form directly or a visitor without JavaScript. |
| ALTCHA: invalid solution | The solution or the challenge was tampered with or is malformed. |
| ALTCHA: challenge expired | The challenge was older than 60 minutes. |
| ALTCHA: solution already used | A solution was submitted a second time (replay). |
| ALTCHA: simulated failure | Created by the test mode "Server rejects the solution". |

Entries created in test mode are marked with `[simulation]`.

## Testing

The setting **Simulate failure (test mode)** lets you check the form behavior and the log without a real spam attack.

The simulation only applies to logged-in users with FormBuilder permission (`form-builder`). Visitors are not affected, even if you forget to switch it off. While a simulation is active, the fieldset label shows **ALTCHA – test mode active**.

| Option | Behavior |
| --- | --- |
| Off | Normal operation. |
| Widget error (mockError) | As in the [ALTCHA playground](https://playground.altcha.org/#/widget): the widget shows its error state and sends no solution. The server rejects the submission and the log shows "ALTCHA: solution missing [simulation]". |
| Server rejects the solution | The widget works normally, but the server rejects the solution anyway. The log shows "ALTCHA: simulated failure [simulation]". |

With the display **Popup on submit**, a widget error prevents the form from being submitted at all. Use **Server rejects the solution** in that case to test the error message and the log.

To test:

1. Select a simulation and save the form.
2. Log in to the admin, open the page with the form in the frontend in the same browser, fill in and submit the form.
3. Check the error message in the form and the entry in **Setup > Logs > form-builder-altcha**.
4. Set the simulation back to **Off** and save.

## Translations

All texts of the module (admin settings, error message, log messages) are translatable with ProcessWire's language support. The source language is English.

### Importing the included German translation

1. Go to **Modules > Site** (or **Modules > Configure**) and open **FormBuilder ALTCHA**.
2. In the module information, the row **Languages** lists `de`. Click **install translations**.
3. For each language that should use German, select `de` under **Import into ...** and submit.

If German is your default language, import into the default language.

### Adding other languages

Go to **Setup > Languages**, edit the language, click **Find Files to Translate** and select `site/modules/FormBuilderAltcha/FormBuilderAltcha.module.php`.

### Widget language

The texts inside the widget come from the ALTCHA widget itself, which includes more than 50 languages. If the setting **Widget language** is blank, the module uses the translatable string `en` (context `widget-language`) as language code. Translate this string to the ISO code of each language (the German translation contains `de`), and the widget follows the language of the current page.

## Troubleshooting

**The widget does not appear**

- Check that both **Filter spam** (in the action list) and **Enable ALTCHA** are checked for the form, and that the form was saved.
- The widget is inserted when FormBuilder renders the form (embed methods A, B and C). Embed method D (custom markup file) has not been tested.

**The widget shows an error**

- Open `https://your-domain/fb-altcha/challenge/` in the browser. It must return JSON starting with `{"parameters":`. If you get a 404 page, check that requests for non-existing paths are passed to ProcessWire (default `.htaccess`) and that no cache or proxy serves this URL.
- The page must be served via HTTPS (see [Requirements](#requirements)).
- If your site sends a Content Security Policy, it must allow the widget's inline styles and web workers created from blob URLs.

**Every submission fails with "solution missing"**

- The widget must be inside the `<form>` element. Custom form markup that removes the widget prevents the solution from being sent.
- Check that no simulation is active for your user.

**Every submission fails with "invalid solution"**

- The challenge is signed with a key derived from `$config->userAuthSalt`. If several servers serve the same site, they must use the same `userAuthSalt`.

## Uninstalling

1. Uncheck **Enable ALTCHA** in the forms where it is used (optional, settings are kept in the form otherwise).
2. Go to **Modules > Site**, open **FormBuilder ALTCHA** and click **Uninstall**.
3. Delete the folder `site/modules/FormBuilderAltcha/`.

## Updating dependencies

The PHP library and the widget are included and do not need Composer. To update them:

1. Run `composer update` in the module directory.
2. Replace `assets/altcha.min.js` with `dist/main/altcha.i18n.min.js` from the npm package [altcha](https://www.npmjs.com/package/altcha) and update the version in the script URL (`?v=`) in `FormBuilderAltcha.module.php`.

Library and widget must use the same protocol version: `altcha-org/altcha` 2.x works with widget 3.x.

## License

MIT, see [LICENSE](LICENSE).

Included third-party code:

- [altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php) 2.3 (MIT) in `vendor/`
- [ALTCHA widget](https://github.com/altcha-org/altcha) 3.3.0 (MIT) in `assets/`, see [assets/LICENSE-altcha.txt](assets/LICENSE-altcha.txt)
