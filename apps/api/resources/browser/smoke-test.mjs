#!/usr/bin/env node
/**
 * End-to-end proof against a throwaway portal on localhost.
 *
 * Deliberately not aimed at any real site: it asserts the behaviours that
 * matter -- token in the body, token only in a later request header, wrong
 * credentials, and no token at all -- against a server whose responses we
 * control, so a failure means this module is wrong rather than that a third
 * party changed their markup.
 *
 * Run with: npm run smoke
 */

import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { extractBearerToken, ExtractionError } from './token-extractor.mjs'

const HERE = dirname(fileURLToPath(import.meta.url))

const VALID_USER = 'trader@example.test'
const VALID_PASSWORD = 'correct horse battery staple'

// A structurally valid JWT (three base64url segments). Not signed by anything
// and not a credential -- it exists so the shape check has something to pass.
const FAKE_JWT = [
  'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
  'eyJzdWIiOiJzbW9rZS10ZXN0IiwiZXhwIjo0MTAyNDQ0ODAwfQ',
  'ZmFrZS1zaWduYXR1cmUtZm9yLWEtbG9jYWwtc21va2UtdGVzdA',
].join('.')

/**
 * @param {'body-only'|'header-only'|'both'|'none'} mode How the portal reveals its token.
 */
function loginPage(mode) {
  return `<!doctype html>
<html><body>
  <form id="f">
    <input type="email" name="email" />
    <input type="password" name="password" />
    <button type="submit">Sign in</button>
  </form>
  <div id="status"></div>
  <script>
    document.getElementById('f').addEventListener('submit', async (event) => {
      event.preventDefault();
      const email = document.querySelector('input[type="email"]').value;
      const password = document.querySelector('input[type="password"]').value;

      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });

      if (!response.ok) {
        document.getElementById('status').textContent = 'rejected';
        return;
      }

      const payload = await response.json();
      document.getElementById('f').remove();
      document.getElementById('status').textContent = 'signed in';

      // The app then uses whatever it was given, exactly as a real one does.
      // In body-only mode it deliberately does not, so the body listener is
      // the only thing that can possibly find the token.
      const sendsAuthorized = ${JSON.stringify(mode === 'header-only' || mode === 'both')};
      const token = payload?.data?.access_token ?? ${JSON.stringify(FAKE_JWT)};
      if (sendsAuthorized) {
        await fetch('/api/me', { headers: { Authorization: 'Bearer ' + token } });
      }
    });
  </script>
</body></html>`
}

function startPortal(mode) {
  const server = createServer((request, response) => {
    const url = request.url ?? '/'

    if (url === '/login') {
      response.writeHead(200, { 'content-type': 'text/html' })
      response.end(loginPage(mode))

      return
    }

    if (url === '/api/auth/login' && request.method === 'POST') {
      const chunks = []
      request.on('data', (chunk) => chunks.push(chunk))
      request.on('end', () => {
        const body = JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}')

        if (body.email !== VALID_USER || body.password !== VALID_PASSWORD) {
          response.writeHead(401, { 'content-type': 'application/json' })
          response.end(JSON.stringify({ message: 'Invalid credentials' }))

          return
        }

        response.writeHead(200, { 'content-type': 'application/json' })

        // "body" hands the token straight back; the other modes withhold it
        // so the header listener (or nothing) has to do the work.
        const revealsInBody = mode === 'body-only' || mode === 'both'

        response.end(
          JSON.stringify(
            revealsInBody
              ? { data: { access_token: FAKE_JWT, refresh_token: 'not-a-jwt' } }
              : { data: { status: 'ok' } },
          ),
        )
      })

      return
    }

    if (url === '/api/me') {
      response.writeHead(200, { 'content-type': 'application/json' })
      response.end(JSON.stringify({ data: { id: 1 } }))

      return
    }

    response.writeHead(404)
    response.end()
  })

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({ server, port: server.address().port })
    })
  })
}

const SELECTORS = {
  username: 'input[type="email"]',
  password: 'input[type="password"]',
  submit: 'button[type="submit"]',
}

async function scenario(name, mode, credentials, assert) {
  const { server, port } = await startPortal(mode)

  try {
    let result = null
    let error = null

    try {
      result = await extractBearerToken({
        loginUrl: `http://127.0.0.1:${port}/login`,
        username: credentials.username,
        password: credentials.password,
        selectors: SELECTORS,
        timeoutMs: 15_000,
      })
    } catch (thrown) {
      error = thrown
    }

    assert(result, error)
    console.log(`  ok   ${name}`)
  } catch (failure) {
    console.error(`  FAIL ${name}: ${failure.message}`)
    process.exitCode = 1
  } finally {
    server.close()
  }
}

function expect(condition, message) {
  if (!condition) throw new Error(message)
}

console.log('browser token extraction, against a local fixture portal:')

await scenario(
  'a token in the login response body is found, with no authorized call to help',
  'body-only',
  { username: VALID_USER, password: VALID_PASSWORD },
  (result, error) => {
    expect(!error, `unexpected error: ${error?.message}`)
    expect(result.token === FAKE_JWT, 'returned the wrong token')
    expect(result.source === 'response-body', `expected response-body, got ${result.source}`)
    // "refresh_token" sits alongside it and is not a JWT: the shape check has
    // to reject it rather than return the first key that merely looks tokenish.
  },
)

await scenario(
  'a portal that reveals the token both ways still returns the right one',
  'both',
  { username: VALID_USER, password: VALID_PASSWORD },
  (result, error) => {
    // Both listeners are live and genuinely race here. Which one wins is
    // timing, and asserting on it would be asserting on a coin toss; what
    // must hold is that either answer is the same correct token.
    expect(!error, `unexpected error: ${error?.message}`)
    expect(result.token === FAKE_JWT, 'returned the wrong token')
  },
)

await scenario(
  'a token that only ever appears in a request header is found',
  'header-only',
  { username: VALID_USER, password: VALID_PASSWORD },
  (result, error) => {
    expect(!error, `unexpected error: ${error?.message}`)
    expect(result.token === FAKE_JWT, 'returned the wrong token')
    expect(result.source === 'request-header', `expected request-header, got ${result.source}`)
  },
)

await scenario(
  'wrong credentials are reported as wrong credentials, not as a timeout',
  'both',
  { username: VALID_USER, password: 'wrong' },
  (result, error) => {
    expect(!result, 'a token was returned for bad credentials')
    expect(
      error?.code === ExtractionError.INVALID_CREDENTIALS,
      `expected INVALID_CREDENTIALS, got ${error?.code}: ${error?.message}`,
    )
    expect(!error.message.includes('wrong'), 'the password leaked into the error message')
  },
)

await scenario(
  'a successful login that never reveals a token is distinguished from a rejection',
  'none',
  { username: VALID_USER, password: VALID_PASSWORD },
  (result, error) => {
    expect(!result, 'a token was returned when none was issued')
    expect(
      error?.code === ExtractionError.TOKEN_NOT_FOUND,
      `expected TOKEN_NOT_FOUND, got ${error?.code}: ${error?.message}`,
    )
  },
)

/**
 * The PHP boundary: a job in on stdin, one JSON object out on stdout.
 *
 * Worth its own scenario because the contract, not the extraction, is what
 * the Laravel side depends on -- and because the password travels through it.
 * Passing secrets on argv would expose them to every user on the box through
 * `ps`, so this asserts stdin carries the job and that the secret does not
 * come back out in the result.
 */
async function runCli(job) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [join(HERE, 'extract-token.mjs')], {
      stdio: ['pipe', 'pipe', 'pipe'],
    })

    let stdout = ''
    let stderr = ''
    child.stdout.on('data', (chunk) => (stdout += chunk))
    child.stderr.on('data', (chunk) => (stderr += chunk))
    child.on('close', (code) => resolve({ code, stdout, stderr }))

    child.stdin.end(JSON.stringify(job))
  })
}

async function cliScenario(name, job, assert) {
  try {
    assert(await runCli(job))
    console.log(`  ok   ${name}`)
  } catch (failure) {
    console.error(`  FAIL ${name}: ${failure.message}`)
    process.exitCode = 1
  }
}

console.log('the CLI contract the Laravel side depends on:')

{
  const { server, port } = await startPortal('body-only')

  try {
    await cliScenario(
      'a job on stdin produces {ok:true, token} and exit 0',
      {
        login_url: `http://127.0.0.1:${port}/login`,
        username: VALID_USER,
        password: VALID_PASSWORD,
        selectors: SELECTORS,
        timeout_ms: 15_000,
      },
      ({ code, stdout, stderr }) => {
        expect(stdout.trim() !== '', `no stdout. exit=${code} stderr=${stderr.slice(0, 400)}`)
        const result = JSON.parse(stdout)
        expect(code === 0, `expected exit 0, got ${code}`)
        expect(result.ok === true, `expected ok:true, got ${stdout}`)
        expect(result.token === FAKE_JWT, 'the CLI returned the wrong token')
      },
    )
  } finally {
    server.close()
  }
}

await cliScenario(
  'a failure is still JSON, exits 1, and never echoes the password',
  {
    // Port 9 (discard) refuses, so this fails in navigation without needing
    // a fixture that has to be told to misbehave.
    login_url: 'http://127.0.0.1:9/login',
    username: VALID_USER,
    password: VALID_PASSWORD,
    selectors: SELECTORS,
    timeout_ms: 8_000,
  },
  ({ code, stdout, stderr }) => {
    const result = JSON.parse(stdout)
    expect(code === 1, `expected exit 1, got ${code}`)
    expect(result.ok === false, 'expected ok:false')
    expect(typeof result.code === 'string', 'a machine-readable code is required')
    expect(!stdout.includes(VALID_PASSWORD), 'the password leaked to stdout')
    expect(!stderr.includes(VALID_PASSWORD), 'the password leaked to stderr')
  },
)

await cliScenario(
  'a malformed job is rejected as BAD_JOB with exit 2',
  'not-an-object',
  ({ code, stdout }) => {
    expect(code === 2, `expected exit 2, got ${code}`)
    expect(JSON.parse(stdout).code === 'BAD_JOB', `expected BAD_JOB, got ${stdout}`)
  },
)

console.log(process.exitCode === 1 ? 'FAILED' : 'all scenarios passed')
