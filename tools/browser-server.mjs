import {spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import {mkdir} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const port = Number(process.env.FORM_GUARD_TEST_PORT || 9473);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid test port.');
const runtime = path.join(root, 'tests', '.runtime', `browser-${randomUUID()}`);
await mkdir(runtime, {recursive: true, mode: 0o700});
const child = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'examples')], {
  cwd: root,
  env: {...process.env, FORM_GUARD_EXAMPLE_RUNTIME: runtime, FORM_GUARD_EXAMPLE_ORIGIN: `http://127.0.0.1:${port}`},
  stdio: 'inherit',
  windowsHide: true,
});
child.on('error', error => {console.error(error.message); process.exitCode = 1;});
child.on('exit', code => {process.exitCode = code || 0;});
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill());
process.on('exit', () => child.kill());
