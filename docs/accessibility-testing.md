[English](accessibility-testing.md) · [Français](accessibility-testing.fr.md)

# Manual accessibility and external integration acceptance

The automatic browser tests are a regression safety net. They do not establish WCAG/RGAA conformity or replace a screen-reader user and an independent developer. The checks below remain pending until their results are recorded by a real reviewer.

## Assistive-technology protocol

Suggested pairs: NVDA + Firefox on Windows and VoiceOver + Safari on macOS/iOS. Record versions, OS, language, commit, tester, date and whether JavaScript was enabled. Test only synthetic addresses (`example.test`). Do not use production contact forms.

1. Start the [example](../README.md#runnable-example), select EN then FR and navigate landmarks/headings. Check document language, title and main landmark.
2. Navigate by Tab and Shift+Tab. The email field must have its visible accessible name, instructions and an appropriate required state. The honeypot and signal fields must never appear in focus order or the accessibility tree.
3. Submit an invalid email, then a valid address after three seconds. Check identification of the error, the result page title, readable feedback and ability to reach the new form.
4. Disable JavaScript, reload and repeat the keyboard submission. Submission must still work; no pointer movement is required.
5. At 200%/400% browser zoom, check reflow and focus visibility. Repeat with enlarged operating-system text and forced colors when available.
6. Use real browser autofill and a password manager. Check the email suggestion, no honeypot filling, and successful submission. The automated prefilling case is not evidence for this step.
7. In an application using AJAX, check that feedback is announced once, that focus remains sensible and that the refreshed form is usable. Form Guard itself does not own the application's live region or focus management.
8. Exercise an expired/consumed form and session loss; confirm understandable recovery. Try honest repeated mistakes and check that a rate-limit message explains the delay.

For each step record `pass`, `fail` or `not tested`, expected/observed behavior and a synthetic reproduction. Do not record an automatic axe pass as a manual screen-reader pass. No manual assistive-technology audit is claimed.

## External developer acceptance

A developer who did not implement the library should receive the README and guides without a walkthrough. In a fresh PHP project, ask them to:

1. Install from the published package or a local checkout and run the example.
2. Integrate a labeled native form, then an AJAX form, keeping session locking and token consumption correct.
3. Choose a stable scope/private runtime and French messages.
4. Diagnose a deliberate invalid token, a 429 and an unavailable storage directory using documented reasons.
5. Schedule maintenance, explain the no-JS path and identify what the application must validate itself.
6. Review upgrade/recovery instructions and describe the limits of the protection in their own words.

Collect setup time, confusing instructions, missing information, defects, commit and platform. Resolve blocking findings and run the regression suites. A fresh automated Composer consumer is useful evidence for packaging; it is not independent developer acceptance.

## Result record

```text
Commit / date / reviewer:
OS / browser / assistive technology / versions:
Language / JS mode:
Step:
Result (pass / fail / not tested):
Expected / observed:
Synthetic reproduction / follow-up:
```
