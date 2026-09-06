#!/usr/bin/env node
/**
 * The process boundary between PHP and Playwright.
 *
 * The job arrives as JSON on **stdin**, never as arguments. Anything on a
 * command line is visible to every user on the box through `ps`, and this job
 * carries a password. The result leaves as a single JSON object on stdout.
 *
 * stdout is reserved for that object so the caller can parse it without
 * guessing; diagnostics go to stderr, and the password is redacted from both.
 *
 * Exit codes: 0 on success, 1 on a handled extraction failure (the JSON says
 * which), 2 when the job itself could not be read.
 */

import { extractBearerToken, redact, TokenExtractionError } from './token-extractor.mjs'

const STDIN_LIMIT_BYTES = 64 * 1024

async function readJob() {
  const chunks = []
  let size = 0

  for await (const chunk of process.stdin) {
    size += chunk.length

    if (size > STDIN_LIMIT_BYTES) {
      throw new Error('The job payload is too large.')
    }

    chunks.push(chunk)
  }

  const raw = Buffer.concat(chunks).toString('utf8').trim()

  if (raw === '') {
    throw new Error('No job was supplied on stdin.')
  }

  const job = JSON.parse(raw)

  // JSON.parse('"text"') succeeds and yields a string, so parsing is not the
  // same as receiving a job. Without this, a malformed payload reaches the
  // extractor as an object with no fields and is reported as an extraction
  // failure rather than as the bad input it is.
  if (job === null || typeof job !== 'object' || Array.isArray(job)) {
    throw new Error('The job must be a JSON object.')
  }

  return job
}

/**
 * Write the result, then exit once it has actually gone.
 *
 * process.exit() does not flush a pending asynchronous write to a pipe, and
 * stdout is a pipe whenever the caller is another process -- which is always,
 * here. Exiting straight after the write truncated the result to nothing and
 * the parent saw empty output on the success path.
 */
function finish(payload, code) {
  process.stdout.write(JSON.stringify(payload) + '\n', () => process.exit(code))
}

async function main() {
  let job

  try {
    job = await readJob()
  } catch (error) {
    finish({ ok: false, code: 'BAD_JOB', message: error.message }, 2)

    return
  }

  const secrets = [job?.password].filter((value) => typeof value === 'string')

  try {
    const { token, source, elapsedMs } = await extractBearerToken({
      loginUrl: job.login_url,
      username: job.username,
      password: job.password,
      selectors: {
        username: job.selectors?.username,
        password: job.selectors?.password,
        submit: job.selectors?.submit,
      },
      timeoutMs: job.timeout_ms,
      headless: job.headless !== false,
      urlHints: job.url_hints,
      tokenKeys: job.token_keys,
      userAgent: job.user_agent,
      executablePath: job.chromium_path || process.env.BROWSER_AUTH_CHROMIUM_PATH || undefined,
    })

    // The token is the only reason this process exists, so it does go to
    // stdout -- but stdout is a pipe to the parent, not a log.
    finish({ ok: true, token, source, elapsed_ms: elapsedMs }, 0)
  } catch (error) {
    const code = error instanceof TokenExtractionError ? error.code : 'UNEXPECTED'

    finish(
      { ok: false, code, message: redact(error?.message ?? 'Unknown failure', secrets) },
      1,
    )
  }
}

main().catch((error) => {
  process.stderr.write(`fatal: ${error?.message ?? error}\n`)
  process.exit(2)
})
