import {test, expect} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const settle = page => page.waitForTimeout(3100); // Exercise the real server-side minimum delay.
const accepted = page => expect(page.getByRole('status')).toContainText(/accepted|acceptée/);

for (const locale of ['en', 'fr']) {
  test(`${locale}: named controls, optional trap and automated accessibility checks`, async ({page}) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`/?lang=${locale}`);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
    await expect(page.getByRole('textbox', {name: locale === 'fr' ? 'Adresse e-mail' : 'Email address'})).toBeVisible();
    await expect(page.locator('input[name^=hp_]')).toBeHidden();
    await expect(page.locator('[name=as_js]')).toHaveValue('1');
    const result = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(result.violations).toEqual([]);
    expect(errors).toEqual([]);
  });
}

for (const javaScriptEnabled of [true, false]) {
  test(`keyboard native submit, JavaScript ${javaScriptEnabled ? 'on' : 'off'}`, async ({browser, baseURL}) => {
    const context = await browser.newContext({javaScriptEnabled});
    try {
      const page = await context.newPage();
      await page.goto(`${baseURL}/?lang=fr`);
      await expect(page.locator('[name=as_js]')).toHaveValue(javaScriptEnabled ? '1' : '0');
      await page.getByRole('textbox').focus();
      await page.keyboard.type('keyboard@example.test');
      await page.keyboard.press('Tab');
      await expect(page.getByRole('button', {name: 'Tester l’envoi'})).toBeFocused();
      await settle(page);
      await page.keyboard.press('Enter');
      await accepted(page);
      if (javaScriptEnabled) {
        const result = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa']).analyze();
        expect(result.violations).toEqual([]);
      }
    } finally {await context.close();}
  });
}

test('prefilled email without input events remains submittable', async ({page}) => {
  await page.goto('/');
  await expect(page.getByRole('textbox')).toHaveAttribute('autocomplete', 'email');
  // Models autofill that does not emit input/change. Native password-manager UI is a manual check.
  await page.getByRole('textbox').evaluate(input => {input.value = 'prefilled@example.test';});
  await settle(page);
  await page.getByRole('button', {name: 'Test submission'}).click();
  await accepted(page);
});

test('native validation keeps the invalid email on the page', async ({page}) => {
  await page.goto('/');
  const id = await page.locator('[name=form_id]').inputValue();
  await page.getByRole('textbox').fill('not-an-email');
  await page.getByRole('button', {name: 'Test submission'}).click();
  expect(await page.getByRole('textbox').evaluate(input => input.validity.typeMismatch)).toBe(true);
  await expect(page.locator('[name=form_id]')).toHaveValue(id);
  await expect(page.getByRole('status')).toHaveCount(0);
});

test('AJAX rejection, accessible feedback and fresh metadata recovery', async ({page}) => {
  await page.goto('/?lang=fr');
  await page.evaluate(() => {
    const form = document.querySelector('form');
    const feedback = document.createElement('p');
    feedback.id = 'ajax-feedback';
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    form.after(feedback);
    window.results = [];
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const response = await fetch(form.action, {method: 'POST', headers: {Accept: 'application/json'}, body: new URLSearchParams(new FormData(form))});
      const data = await response.json();
      window.results.push({status: response.status, ...data});
      feedback.textContent = data.message;
      form.querySelector('[name=form_id]').value = data.form.id;
      form.querySelector('[name=csrf_token]').value = data.form.csrf;
      form.querySelector('#email').name = data.form.fields.email;
      form.querySelector('[name^=hp_]').name = data.form.fields.honeypot;
      form.querySelector('[name=as_trap]').value = data.form.js_trap;
      window.FormGuard.refresh(form);
    });
    form.querySelector('[name=csrf_token]').value = 'invalid-token';
  });
  await page.getByRole('textbox').fill('ajax@example.test');
  await page.getByRole('button', {name: 'Tester l’envoi'}).click();
  await expect.poll(() => page.evaluate(() => window.results.length)).toBe(1);
  expect(await page.evaluate(() => window.results[0].reason)).toBe('invalid_token');
  await expect(page.getByRole('status')).toContainText('formulaire');
  await expect(page.locator('[name=as_interactions]')).toHaveValue('0');
  await settle(page);
  await page.getByRole('button', {name: 'Tester l’envoi'}).click();
  await expect.poll(() => page.evaluate(() => window.results.length)).toBe(2);
  await accepted(page);
  expect(await page.evaluate(() => window.results[1].ok)).toBe(true);
});

test('consumed form cannot be replayed after navigating back', async ({page}) => {
  await page.goto('/');
  const original = await page.locator('form').evaluate(form => Object.fromEntries(new FormData(form)));
  const emailName = await page.getByRole('textbox').getAttribute('name');
  original[emailName] = 'replay@example.test';
  await page.getByRole('textbox').fill('replay@example.test');
  await settle(page);
  await page.getByRole('button', {name: 'Test submission'}).click();
  await accepted(page);
  await page.goBack();
  const result = await page.evaluate(async data => {
    const response = await fetch('/', {method: 'POST', headers: {Accept: 'application/json'}, body: new URLSearchParams(data)});
    return {status: response.status, ...await response.json()};
  }, original);
  expect(result.status).toBe(403);
  expect(result.reason).toBe('form_unavailable');
});

test('lost session gives a recoverable response with a usable new form', async ({page, context}) => {
  await page.goto('/');
  await page.getByRole('textbox').fill('session@example.test');
  await context.clearCookies();
  await page.getByRole('button', {name: 'Test submission'}).click();
  await expect(page.getByRole('status')).toContainText('reload');
  await expect(page.getByRole('textbox')).toBeVisible();
});

test('320 CSS pixels and enlarged text have no horizontal overflow', async ({page}) => {
  await page.setViewportSize({width: 320, height: 900});
  await page.goto('/?lang=fr');
  await page.addStyleTag({content: 'html { font-size: 200%; }'});
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByRole('textbox').focus();
  await expect(page.getByRole('textbox')).toBeFocused();
});
