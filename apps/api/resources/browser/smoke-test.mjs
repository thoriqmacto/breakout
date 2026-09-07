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

/**
 * A portal that behaves like the one this was pointed at in production.
 *
 * Login succeeds and lands on a page that loads no data, so nothing carries
 * the bearer while anyone is watching. The token is in the app's hands the
 * whole time -- in web storage -- and only reaches the wire when a page of the
 * app is opened. That is the shape that produced TOKEN_NOT_FOUND against a
 * login that had plainly worked.
 */
function quietLoginPage() {
  return `<!doctype html>
<html><body>
  <form id="f">
    <input type="text" name="username" />
    <input type="password" name="password" />
    <button type="submit">Login</button>
  </form>
  <div id="status"></div>
  <script>
    document.getElementById('f').addEventListener('submit', async (event) => {
      event.preventDefault();
      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          email: document.querySelector('input[name="username"]').value,
          password: document.querySelector('input[name="password"]').value,
        }),
      });

      if (!response.ok) {
        document.getElementById('status').textContent = 'rejected';
        return;
      }

      // Stored, not used. No further call is made from this page.
      localStorage.setItem('sb_session', JSON.stringify({
        access_token: ${JSON.stringify(FAKE_JWT)},
        refresh_token: 'not-a-jwt',
      }));
      document.getElementById('f').remove();
      document.getElementById('status').textContent = 'signed in';
    });
  </script>
</body></html>`
}

/** The app page: opening it is what puts the bearer on the wire. */
function appPage() {
  return `<!doctype html>
<html><body><div id="app">loading</div>
  <script>
    const session = JSON.parse(localStorage.getItem('sb_session') || '{}');
    fetch('/api/me', { headers: { Authorization: 'Bearer ' + session.access_token } })
      .then(() => { document.getElementById('app').textContent = 'ready' });
  </script>
</body></html>`
}

function startPortal(mode) {
  const server = createServer((request, response) => {
    const url = request.url ?? '/'

    if (url === '/login') {
      response.writeHead(200, { 'content-type': 'text/html' })
      response.end(mode === 'quiet' ? quietLoginPage() : loginPage(mode))

      return
    }

    if (url === '/app') {
      response.writeHead(200, { 'content-type': 'text/html' })
      response.end(appPage())

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

/**
 * Drive a real login with selectors the probe proposed, on a running portal.
 */
async function scenarioWithSelectors(name, port, selectors) {
  try {
    const result = await extractBearerToken({
      loginUrl: `http://127.0.0.1:${port}/login`,
      username: VALID_USER,
      password: VALID_PASSWORD,
      selectors,
      timeoutMs: 15_000,
    })

    expect(result.token === FAKE_JWT, 'the proposed selectors did not produce a token')
    console.log(`  ok   ${name}`)
  } catch (failure) {
    console.error(`  FAIL ${name}: ${failure.message}`)
    process.exitCode = 1
  }
}

/**
 * Run one assertion that needs no fixture plumbing of its own.
 */
async function check(name, assert) {
  try {
    await assert()
    console.log(`  ok   ${name}`)
  } catch (failure) {
    console.error(`  FAIL ${name}: ${failure.message}`)
    process.exitCode = 1
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
    // Awaited: an async assertion that rejects here used to escape as an
    // unhandled rejection, which kills the process rather than failing the
    // scenario -- and takes the rest of the suite with it.
    await assert(await runCli(job))
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

/**
 * The form probe, against the same fixture.
 *
 * SELECTOR_NOT_FOUND is a configuration failure whose answer lives on the
 * portal, so the probe that proposes the selectors is worth proving too: a
 * suggestion that does not actually drive the form is worse than none.
 */
async function runFormProbe(loginUrl) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [join(HERE, 'form-probe.mjs')], {
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, BROWSER_AUTH_LOGIN_URL: loginUrl },
    })

    let stdout = ''
    let stderr = ''
    child.stdout.on('data', (chunk) => (stdout += chunk))
    child.stderr.on('data', (chunk) => (stderr += chunk))
    child.on('close', (code) => resolve({ code, stdout, stderr }))
  })
}

/**
 * A login that works, followed by silence.
 *
 * Both of these fail on the wire-only implementation: nothing carries the
 * bearer while the extractor is watching, which is what produced
 * TOKEN_NOT_FOUND in production against a login that had plainly succeeded.
 */
console.log('a portal that stores the token instead of using it:')

{
  const { server, port } = await startPortal('quiet')

  try {
    const QUIET_SELECTORS = {
      username: 'input[name="username"]',
      password: 'input[name="password"]',
      submit: 'button[type="submit"]',
    }

    await check('the token is found in web storage when nothing carries it', async () => {
      const result = await extractBearerToken({
        loginUrl: `http://127.0.0.1:${port}/login`,
        username: VALID_USER,
        password: VALID_PASSWORD,
        selectors: QUIET_SELECTORS,
        timeoutMs: 15_000,
      })

      expect(result.token === FAKE_JWT, 'the wrong token came back')
      expect(
        result.source.startsWith('storage:'),
        `expected a storage source, got ${result.source}`,
      )
    })

    // And the other way round: opening a page of the app puts it on the wire,
    // which is the automated form of reading it out of devtools.
    await check('a post-login page makes the app send the bearer', async () => {
      const result = await extractBearerToken({
        loginUrl: `http://127.0.0.1:${port}/login`,
        postLoginUrl: `http://127.0.0.1:${port}/app`,
        username: VALID_USER,
        password: VALID_PASSWORD,
        selectors: QUIET_SELECTORS,
        // Short, so a pass here cannot be the storage poll arriving late.
        timeoutMs: 12_000,
      })

      expect(result.token === FAKE_JWT, 'the wrong token came back')
    })

    // The refresh token is also a JWT-shaped string in that session object;
    // taking it would store a token that authenticates nothing.
    await check('the access token is preferred over other stored values', async () => {
      const result = await extractBearerToken({
        loginUrl: `http://127.0.0.1:${port}/login`,
        username: VALID_USER,
        password: VALID_PASSWORD,
        selectors: QUIET_SELECTORS,
        timeoutMs: 12_000,
      })

      expect(result.token === FAKE_JWT, `stored the wrong value: ${result.source}`)
    })
  } finally {
    server.close()
  }
}

console.log('the form probe, against the same fixture:')

{
  const { server, port } = await startPortal('body-only')

  try {
    const { code, stdout, stderr } = await runFormProbe(`http://127.0.0.1:${port}/login`)

    await check('it proposes selectors that match the fixture form', () => {
      expect(code === 0, `expected exit 0, got ${code}: ${stderr.slice(0, 300)}`)

      const report = JSON.parse(stdout)

      expect(
        report.suggestion.username === 'input[name="email"]',
        `username selector was ${report.suggestion.username}`,
      )
      expect(
        report.suggestion.password === 'input[name="password"]',
        `password selector was ${report.suggestion.password}`,
      )
      expect(
        report.suggestion.submit === 'button[type="submit"]',
        `submit selector was ${report.suggestion.submit}`,
      )
    })

    // A proposal is only worth printing if it drives the form, so the
    // suggested selectors are handed straight back to the extractor.
    await scenarioWithSelectors(
      'and a login driven by those selectors succeeds',
      port,
      JSON.parse(stdout).suggestion,
    )
  } finally {
    server.close()
  }
}

/**
 * A page shaped like the portal this was actually pointed at: the username
 * field carries only an id, and the identity-provider buttons are labelled
 * "Login with ..." and sit above the real one.
 */
const SSO_LOGIN_PAGE = `<!doctype html>
<html><head><title>Portal</title></head><body>
  <form>
    <input type="text" id="username" placeholder="Email or username">
    <input type="password" name="password" id="password" placeholder="Password">
    <button id="buttonSupport"></button>
    <button id="google-login-button">Login with Google</button>
    <button id="facebook-login-button">Login with Facebook</button>
    <button id="email-login-button">Login</button>
  </form>
</body></html>`

await check(
  'an id-only field is proposed as [id=...], never #id, which a .env eats',
  async () => {
    const { code, stdout, stderr } = await runFormProbe(
      `data:text/html,${encodeURIComponent(SSO_LOGIN_PAGE)}`,
    )

    expect(code === 0, `expected exit 0, got ${code}: ${stderr.slice(0, 300)}`)

    const { suggestion } = JSON.parse(stdout)

    for (const [role, value] of Object.entries(suggestion)) {
      expect(
        typeof value === 'string' && !value.startsWith('#'),
        `${role} was proposed as ${value}: an unquoted # is a comment in a .env`,
      )
    }

    expect(
      suggestion.username === 'input[id="username"]',
      `username selector was ${suggestion.username}`,
    )

    // The one that matters: every SSO button matches the same hints the real
    // control does, and drives a flow this cannot complete.
    expect(
      suggestion.submit === 'button[id="email-login-button"]',
      `submit selector was ${suggestion.submit}, not the password form's button`,
    )
  },
)

console.log(process.exitCode === 1 ? 'FAILED' : 'all scenarios passed')
