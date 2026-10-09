// Post-deploy smoke test against a live site with the official MCP SDKs (install them as scripts/e2e/live.sh does).
// Writes only to the key owner's private files (one random 50 MB file, deleted at the end). Expects MCP_URL and MCP_KEY.
import { createHash, randomBytes } from 'node:crypto';
import { Client as ClientV2, StreamableHTTPClientTransport as TransportV2 } from '@modelcontextprotocol/client';
import { Client as ClientV1 } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport as TransportV1 } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const URL_ = new URL(process.env.MCP_URL);
const headers = { Authorization: `Bearer ${process.env.MCP_KEY}` };
let failures = 0;
const check = (label, ok, detail = '') => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${!ok && detail ? `  -> ${String(detail).slice(0, 300)}` : ''}`);
  if (!ok) failures++;
};
const attempt = async (label, fn) => { try { await fn(); } catch (e) { check(label, false, e?.message ?? String(e)); } };
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const sc = (r) => r.structuredContent ?? JSON.parse(r.content?.[0]?.text ?? '{}');

async function connect(sdk, mode) {
  if (sdk === 'v1') {
    const c = new ClientV1({ name: 'prod-smoke-v1', version: '1.0.0' }, { capabilities: {} });
    await c.connect(new TransportV1(URL_, { requestInit: { headers } }));
    return c;
  }
  const options = { capabilities: {} };
  if (mode !== 'legacy') options.versionNegotiation = { mode: mode === 'pin' ? { pin: '2026-07-28' } : 'auto' };
  const c = new ClientV2({ name: 'prod-smoke-v2', version: '1.0.0' }, options);
  await c.connect(new TransportV2(URL_, { requestInit: { headers } }));
  return c;
}

for (const [sdk, mode] of [['v2', 'auto'], ['v2', 'pin'], ['v1', 'legacy']]) {
  const tag = `[${sdk}/${mode}]`;
  await attempt(`${tag} surfaces`, async () => {
    const t0 = Date.now();
    const c = await connect(sdk, mode);
    const version = c.getNegotiatedProtocolVersion?.() ?? '(v1 legacy)';
    check(`${tag} connected, protocol ${version} (${Date.now() - t0} ms)`, true);
    const t1 = Date.now();
    const tools = (await c.listTools()).tools;
    check(`${tag} tools/list: ${tools.length} tools in ${Date.now() - t1} ms, names valid`,
      tools.length > 40 && tools.every((t) => /^[a-zA-Z0-9_-]{1,128}$/.test(t.name)));
    const site = JSON.parse((await c.readResource({ uri: 'moodle://site' })).contents[0].text);
    check(`${tag} resources/read moodle://site`, site.siteurl === 'https://learn.aspireschool.org', site.siteurl);
    check(`${tag} prompts/list`, (await c.listPrompts()).prompts.length > 3);
    await c.close();
  });
}

await attempt('[files] 50 MB round trip', async () => {
  const c = await connect('v2', 'auto');
  const payload = randomBytes(50 * 1024 * 1024);
  const name = `mcp-smoke-${Date.now()}.bin`;
  const ticket = sc(await c.callTool({ name: 'file_create_upload_url', arguments: { filename: name } }));
  let t = Date.now();
  const put = await fetch(ticket.url, { method: 'PUT', body: payload });
  const up = await put.json();
  check(`[files] PUT 50 MB in ${Date.now() - t} ms`, put.ok && up.files?.[0]?.size === payload.length, JSON.stringify(up));
  const saved = await c.callTool({ name: 'file_save_draft', arguments: { draftitemid: up.draftitemid ?? ticket.draftitemid } });
  check('[files] saved to private files', !saved.isError, saved.content?.[0]?.text);
  const listed = sc(await c.callTool({ name: 'file_list', arguments: { component: 'user', filearea: 'private' } }));
  const uri = (listed.entries ?? []).find((f) => f.filename === name || f.name === name)?.uri;
  check('[files] listed', !!uri, JSON.stringify(listed).slice(0, 300));
  const link = sc(await c.callTool({ name: 'file_get_download_url', arguments: { uri } }));
  t = Date.now();
  const res = await fetch(link.url);
  const got = Buffer.from(await res.arrayBuffer());
  check(`[files] download in ${Date.now() - t} ms, sha256 matches`, sha(got) === sha(payload), `${got.length} bytes`);
  check('[files] safe headers', res.headers.get('x-content-type-options') === 'nosniff', [...res.headers].join('; '));
  const range = await fetch(link.url, { headers: { Range: 'bytes=0-99' } });
  check('[files] HTTP Range', range.status === 206, String(range.status));
  const del = await c.callTool({ name: 'file_delete', arguments: { uri } });
  check('[files] cleanup deleted the test file', !del.isError, del.content?.[0]?.text);
  await c.close();
});

await attempt('[gateway] search + execute', async () => {
  const c = await connect('v2', 'auto');
  const search = sc(await c.callTool({ name: 'wrapper_moodle_api_search', arguments: { query: 'site info' } }));
  check('[gateway] search finds core_webservice_get_site_info', JSON.stringify(search).includes('core_webservice_get_site_info'));
  const exec = await c.callTool({ name: 'wrapper_moodle_api_execute', arguments: { functionname: 'core_webservice_get_site_info', params: {} } });
  check('[gateway] execute', !exec.isError, exec.content?.[0]?.text);
  await c.close();
});

console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nALL PRODUCTION SMOKE CHECKS PASSED');
process.exit(failures ? 1 : 0);
