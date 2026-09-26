#!/usr/bin/env node
// Legal watch. Asks a model, through the Manifest LLM Gateway, whether this
// pull request makes a sentence of manifest.build/terms or /privacy false,
// and comments on the pull request when it does. It never fails the build:
// every error is logged and the script exits 0.
//
// Env: REPO, PR_NUMBER, GH_TOKEN (read by the gh CLI), MANIFEST_GATEWAY_KEY.
// Optional: LEGAL_WATCH_BASE_URL, LEGAL_WATCH_MODEL, LEGAL_WATCH_REVIEWER,
// LEGAL_WATCH_DRY_RUN=1 (prints the request instead of calling the model).
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const { REPO, PR_NUMBER, MANIFEST_GATEWAY_KEY: KEY } = process.env;
const BASE = (process.env.LEGAL_WATCH_BASE_URL || 'https://app.manifest.build/v1').replace(/\/+$/, '');
const MODEL = process.env.LEGAL_WATCH_MODEL || 'auto';
const REVIEWER = process.env.LEGAL_WATCH_REVIEWER || 'SebConejo';
const DRY_RUN = process.env.LEGAL_WATCH_DRY_RUN === '1';
const PAGES = { terms: 'https://manifest.build/terms/', privacy: 'https://manifest.build/privacy/' };
const PAGE_NAMES = { terms: 'Terms of Service', privacy: 'Privacy Policy' };
const MAX_DIFF_CHARS = 120_000;
const MARKER = '<!-- legal-watch';

// Files that never change what the product does with data.
const IGNORED = /(^|\/)(tests?|__tests__|__mocks__|fixtures?)\/|\.(test|spec)\.[cm]?[jt]sx?$|(^|\/)test_[^/]*\.py$|(^|\/)(package-lock\.json|pnpm-lock\.yaml|yarn\.lock|poetry\.lock|uv\.lock)$|\.(md|snap)$/;

const gh = (args, input) =>
  execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, input });

/** The visible text of a legal page: what sits inside <main>, one block per line. */
function pageText(html) {
  const start = html.indexOf('<main');
  const end = html.indexOf('</main>');
  let s = start >= 0 && end > start ? html.slice(start, end) : html;
  s = s.replace(/<(script|style|svg|noscript)[\s\S]*?<\/\1>/gi, ' ');
  s = s.replace(/<(h[1-6])[^>]*>/gi, '\n\n## ').replace(/<li[^>]*>/gi, '\n- ').replace(/<(p|br|div|ul|ol)[^>]*>/gi, '\n');
  s = s.replace(/<[^>]+>/g, '');
  s = s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;|&rsquo;/g, "'").replace(/&nbsp;/g, ' ');
  return s.split('\n').map((l) => l.replace(/\s+/g, ' ').trim()).join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

/** The diff without files that cannot change data handling, capped in size. */
function relevantDiff(raw) {
  const kept = raw
    .split(/^(?=diff --git )/m)
    .filter((part) => {
      const file = part.match(/^diff --git a\/(\S+)/)?.[1];
      return file && !IGNORED.test(file);
    })
    .join('');
  return kept.length > MAX_DIFF_CHARS
    ? { diff: kept.slice(0, MAX_DIFF_CHARS), truncated: true }
    : { diff: kept, truncated: false };
}

/** The first JSON object in the model's answer, fenced or not. */
function parseVerdict(text) {
  const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  const candidate = fenced ? fenced[1] : text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1);
  try {
    const verdict = JSON.parse(candidate);
    return Array.isArray(verdict.findings) ? verdict : null;
  } catch {
    return null;
  }
}

const quote = (s) => String(s).split('\n').map((l) => `> ${l}`).join('\n');

function renderComment(verdict, findings, model, hash, truncated) {
  const items = findings.map((f, i) => {
    const where = `${PAGE_NAMES[f.page] || f.page}, "${f.section || 'section not named'}"`;
    const current = f.current ? `Current:\n${quote(f.current)}` : 'Current: nothing on the page covers this.';
    const label = f.kind === 'missing' ? 'Add' : 'Proposed';
    return [
      `**${i + 1}. ${where}** (${f.kind || 'change'}, confidence: ${f.confidence || 'unknown'})`,
      '',
      f.reason || '',
      '',
      current,
      '',
      `${label}:\n${quote(f.proposed)}`,
    ].join('\n');
  });
  return [
    `${MARKER} ${hash} -->`,
    `@${REVIEWER} this pull request may make the legal pages out of date.`,
    '',
    verdict.summary || '',
    '',
    ...items.flatMap((item) => [item, '']),
    '---',
    `Checked by legal-watch with \`${model}\` through the Manifest LLM Gateway, against the live [Terms](${PAGES.terms}) and [Privacy Policy](${PAGES.privacy}).` +
      (truncated ? ' The diff was too long and was cut, so part of it was not read.' : ''),
  ].join('\n');
}

async function main() {
  if (!REPO || !PR_NUMBER) return console.log('legal-watch: REPO or PR_NUMBER is missing; skipping.');
  if (!KEY && !DRY_RUN) return console.log('legal-watch: MANIFEST_GATEWAY_KEY is not set; skipping.');

  const pr = JSON.parse(gh(['pr', 'view', PR_NUMBER, '--repo', REPO, '--json', 'title,body,files']));
  const { diff, truncated } = relevantDiff(gh(['pr', 'diff', PR_NUMBER, '--repo', REPO]));
  if (!diff.trim()) return console.log('legal-watch: only tests, lockfiles or docs changed; nothing to check.');

  const [terms, privacy] = await Promise.all(
    Object.values(PAGES).map(async (url) => {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`${url} answered ${res.status}`);
      return pageText(await res.text());
    }),
  );

  const system = readFileSync(new URL('./prompt.md', import.meta.url), 'utf8');
  const user = [
    `Repository: ${REPO}`,
    `Pull request #${PR_NUMBER}: ${pr.title}`,
    `Description:\n${(pr.body || '(none)').slice(0, 4000)}`,
    `Changed files:\n${pr.files.map((f) => `- ${f.path} (+${f.additions} -${f.deletions})`).join('\n')}`,
    `Diff${truncated ? ' (truncated)' : ''}:\n${diff}`,
    `Current Terms of Service (${PAGES.terms}):\n${terms}`,
    `Current Privacy Policy (${PAGES.privacy}):\n${privacy}`,
  ].join('\n\n');

  if (DRY_RUN) {
    console.log(`legal-watch dry run: ${user.length} characters for ${MODEL} at ${BASE}`);
    console.log(user.slice(0, 3000));
    return;
  }

  const res = await fetch(`${BASE}/chat/completions`, {
    method: 'POST',
    headers: { authorization: `Bearer ${KEY}`, 'content-type': 'application/json' },
    body: JSON.stringify({
      model: MODEL,
      temperature: 0,
      messages: [
        { role: 'system', content: system },
        { role: 'user', content: user },
      ],
    }),
  });
  if (!res.ok) return console.log(`legal-watch: the gateway answered ${res.status}: ${(await res.text()).slice(0, 500)}`);
  const data = await res.json();
  const answer = data.choices?.[0]?.message?.content ?? '';
  const verdict = parseVerdict(answer);
  if (!verdict) return console.log(`legal-watch: could not read the model's answer:\n${answer.slice(0, 1000)}`);

  const findings = verdict.findings.filter((f) => f && f.proposed);
  console.log(`legal-watch: ${findings.length} finding(s). ${verdict.summary || ''}`);
  if (!findings.length) return;

  // One comment per distinct set of findings: a new push with the same
  // findings does not ping again.
  const hash = createHash('sha256').update(JSON.stringify(findings)).digest('hex').slice(0, 12);
  const posted = gh(['api', `repos/${REPO}/issues/${PR_NUMBER}/comments`, '--paginate', '--jq', '.[].body']);
  if (posted.includes(`${MARKER} ${hash} -->`)) return console.log('legal-watch: these findings are already posted.');

  gh(['pr', 'comment', PR_NUMBER, '--repo', REPO, '--body-file', '-'], renderComment(verdict, findings, data.model || MODEL, hash, truncated));
  console.log('legal-watch: comment posted.');
}

export { pageText, relevantDiff, parseVerdict, renderComment };

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((err) => {
    console.log(`legal-watch: ${err.message}`);
  });
}
