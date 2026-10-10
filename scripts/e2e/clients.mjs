// Live end-to-end checks with the official MCP TypeScript SDKs (v2 = 2026-07-28 runtime, v1 = 2025 legacy).
// Run by scripts/e2e/live.sh; expects MCP_URL, MCP_SEED and MCP_{ADMIN,TEACHER,STUDENT}_KEY in the environment.
import { createHash, randomBytes } from 'node:crypto';
import { Client as ClientV2, StreamableHTTPClientTransport as TransportV2 } from '@modelcontextprotocol/client';
import { Client as ClientV1 } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport as TransportV1 } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const URL_ = new URL(process.env.MCP_URL);
const seed = JSON.parse(process.env.MCP_SEED);
const keys = { admin: process.env.MCP_ADMIN_KEY, teacher: process.env.MCP_TEACHER_KEY, student: process.env.MCP_STUDENT_KEY };
let failures = 0;

const check = (label, ok, detail = '') => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${!ok && detail ? `  -> ${detail}` : ''}`);
  if (!ok) failures++;
};
const attempt = async (label, fn) => {
  try { await fn(); } catch (e) { check(label, false, e?.message ?? String(e)); }
};
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const sc = (result) => result.structuredContent ?? JSON.parse(result.content?.[0]?.text ?? '{}');

async function connect(sdk, mode, who) {
  const headers = { Authorization: `Bearer ${keys[who]}` };
  if (sdk === 'v1') {
    const client = new ClientV1({ name: 'e2e-v1', version: '1.0.0' }, { capabilities: {} });
    await client.connect(new TransportV1(URL_, { requestInit: { headers } }));
    return client;
  }
  const options = { capabilities: { elicitation: { form: {} } } };
  if (mode !== 'legacy') {
    options.versionNegotiation = { mode: mode === 'pin' ? { pin: '2026-07-28' } : 'auto' };
  }
  const client = new ClientV2({ name: 'e2e-v2', version: '1.0.0' }, options);
  await client.connect(new TransportV2(URL_, { requestInit: { headers } }));
  return client;
}

async function surfaces(sdk, mode) {
  const tag = `[${sdk}/${mode}]`;
  await attempt(`${tag} surfaces`, async () => {
    const client = await connect(sdk, mode, 'teacher');
    const version = client.getNegotiatedProtocolVersion?.() ?? '(v1)';
    check(`${tag} connected, protocol ${version}`, true);
    if (mode === 'auto' || mode === 'pin') {
      check(`${tag} negotiated modern 2026-07-28`, version === '2026-07-28', version);
    }
    check(`${tag} server instructions present`, (client.getInstructions() ?? '').includes('Moodle'));

    const tools = (await client.listTools()).tools;
    const names = tools.map((t) => t.name);
    for (const expected of ['file_list', 'file_read', 'file_create_upload_url', 'wrapper_moodle_api_search', 'moodle_explorer']) {
      check(`${tag} tools/list includes ${expected}`, names.includes(expected));
    }
    check(`${tag} ${tools.length} tools, all names valid`, tools.every((t) => /^[a-zA-Z0-9_-]{1,128}$/.test(t.name)));

    const explorer = await client.callTool({ name: 'moodle_explorer', arguments: { view: 'course', courseid: seed.courseid } });
    const sections = sc(explorer).sections ?? [];
    check(`${tag} moodle_explorer course view`, sections.some((s) => s.modules.some((m) => m.name === 'Syllabus')));

    const resources = (await client.listResources()).resources.map((r) => r.uri);
    check(`${tag} resources/list has course`, resources.includes(`moodle://course/${seed.courseid}`));
    const templates = (await client.listResourceTemplates()).resourceTemplates;
    check(`${tag} resource templates`, templates.some((t) => t.uriTemplate.startsWith('moodle://file/')));
    const course = await client.readResource({ uri: `moodle://course/${seed.courseid}` });
    check(`${tag} resources/read course`, JSON.parse(course.contents[0].text).course.shortname === 'E2EBIO');
    const skill = await client.readResource({ uri: 'skill://moodle-file-transfer/SKILL.md' });
    check(`${tag} skill readable`, skill.contents[0].text.startsWith('---\nname: moodle-file-transfer'));

    const prompts = (await client.listPrompts()).prompts.map((p) => p.name);
    check(`${tag} prompts/list`, prompts.includes('course_overview'));
    const prompt = await client.getPrompt({ name: 'course_overview', arguments: { courseid: String(seed.courseid) } });
    check(`${tag} prompts/get`, prompt.messages.length >= 1);
    const completion = await client.complete({
      ref: { type: 'ref/prompt', name: 'course_overview' }, argument: { name: 'courseid', value: 'bio' },
    });
    check(`${tag} completion/complete`, completion.completion.values.includes(String(seed.courseid)));
    await client.close();
  });
}

async function files() {
  const tag = '[v2/auto files]';
  await attempt(`${tag} flow`, async () => {
    const client = await connect('v2', 'auto', 'teacher');
    const payload = randomBytes(3 * 1024 * 1024 + 17);

    const ticket = sc(await client.callTool({ name: 'file_create_upload_url', arguments: { filename: 'notes.bin' } }));
    check(`${tag} upload URL issued`, typeof ticket.url === 'string', JSON.stringify(ticket).slice(0, 200));
    const put = await fetch(ticket.url, { method: 'PUT', body: payload });
    const uploaded = await put.json();
    check(`${tag} PUT upload (${payload.length} bytes)`, put.ok && uploaded.files?.[0]?.size === payload.length,
      JSON.stringify(uploaded).slice(0, 300));

    const saved = await client.callTool({
      name: 'file_save_draft', arguments: { draftitemid: uploaded.draftitemid ?? ticket.draftitemid },
    });
    check(`${tag} saved draft to private files`, !saved.isError, saved.content?.[0]?.text?.slice(0, 300));

    const listed = sc(await client.callTool({ name: 'file_list', arguments: { component: 'user', filearea: 'private' } }));
    const entry = JSON.stringify(listed).includes('notes.bin');
    check(`${tag} file_list shows private file`, entry, JSON.stringify(listed).slice(0, 300));
    const uri = (listed.entries ?? []).find((f) => f.filename === 'notes.bin' || f.name === 'notes.bin')?.uri;

    const link = sc(await client.callTool({ name: 'file_get_download_url', arguments: { uri } }));
    const got = Buffer.from(await (await fetch(link.url)).arrayBuffer());
    check(`${tag} download matches upload (sha256)`, sha(got) === sha(payload), `${got.length} bytes`);
    const range = await fetch(link.url, { headers: { Range: 'bytes=0-99' } });
    check(`${tag} HTTP Range supported`, range.status === 206 && (await range.arrayBuffer()).byteLength === 100,
      String(range.status));

    const syllabus = await client.callTool({
      name: 'file_list', arguments: { courseid: seed.courseid, recursive: true },
    });
    check(`${tag} course file listing`, JSON.stringify(sc(syllabus)).includes('syllabus.txt'));

    const search = sc(await client.callTool({ name: 'wrapper_moodle_api_search', arguments: { query: 'course contents' } }));
    check(`${tag} api search finds core_course_get_contents`, JSON.stringify(search).includes('core_course_get_contents'));
    const exec = await client.callTool({
      name: 'wrapper_moodle_api_execute', arguments: { functionname: 'core_course_get_contents', params: { courseid: seed.courseid } },
    });
    check(`${tag} api execute core_course_get_contents`, !exec.isError, exec.content?.[0]?.text?.slice(0, 200));
    await client.close();
  });

  await attempt('[v2/auto student] permission boundary', async () => {
    const client = await connect('v2', 'auto', 'student');
    const denied = await client.callTool({
      name: 'wrapper_moodle_api_execute',
      arguments: { functionname: 'core_course_delete_courses', params: { courseids: [seed.courseid] } },
    });
    // Moodle refuses either with an exception (isError) or with a permission warning in the result.
    const refused = denied.isError === true || JSON.stringify(sc(denied)).includes('cannotdeletecourse');
    check('[v2/auto student] cannot delete a course', refused, denied.content?.[0]?.text);
    await client.close();
  });
}

// Every tool, called once with empty arguments over real HTTP (cold requests): validation errors are fine, but a PHP
// fatal (masked as "Internal error") means a missing library include that PHPUnit's preloading hides.
async function coldcalls() {
  const client = await connect('v2', 'auto', 'admin');
  const tools = (await client.listTools()).tools;
  const fatal = [];
  for (const tool of tools) {
    const r = await client.callTool({ name: tool.name, arguments: {} }).catch((e) => ({ isError: true, content: [{ text: e.message }] }));
    if (/Internal error/i.test(r.content?.[0]?.text ?? '')) fatal.push(tool.name);
  }
  for (const [name, args] of [['backup_status', { backupid: 'nosuchbackup' }], ['backup_status', { backupid: 'export1' }]]) {
    const r = await client.callTool({ name, arguments: args });
    if (/Internal error/i.test(r.content?.[0]?.text ?? '')) fatal.push(`${name}(${args.backupid})`);
  }
  check(`[cold] ${tools.length} tools called with empty args, no PHP fatals`, fatal.length === 0, fatal.join(', '));
  await client.close();
}

async function raw() {
  const base = URL_.href;
  const card = await fetch(`${base}/server-card`);
  const cardjson = await card.json();
  check('[http] server card', card.ok && cardjson.remotes?.[0]?.url === base, JSON.stringify(cardjson).slice(0, 200));

  const unauth = await fetch(base, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/list' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: { _meta: {
      'io.modelcontextprotocol/protocolVersion': '2026-07-28', 'io.modelcontextprotocol/clientCapabilities': {} } } }),
  });
  check('[http] unauthenticated -> 401 with resource_metadata',
    unauth.status === 401 && (unauth.headers.get('www-authenticate') ?? '').includes('resource_metadata'));

  // Explicit index.php: PHP's built-in server never resolves directory indexes under dot-dirs (nginx does: 301 -> 200).
  const prm = await fetch(new URL('/webservice/mcp/.well-known/oauth-protected-resource/index.php', base));
  check('[http] protected resource metadata', prm.ok && (await prm.json()).authorization_servers?.length > 0);

  // Legacy 2025-11-25 task augmentation through raw JSON-RPC.
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream',
    Authorization: `Bearer ${keys.teacher}` };
  const init = await fetch(base, { method: 'POST', headers: { ...headers, 'Mcp-Method': 'initialize' }, body: JSON.stringify({
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'raw', version: '1' } } }) });
  const session = init.headers.get('mcp-session-id');
  const legacy = { ...headers, 'Mcp-Session-Id': session, 'MCP-Protocol-Version': '2025-11-25' };
  const rpc = async (id, method, params) => (await (await fetch(base, { method: 'POST',
    headers: { ...legacy, 'Mcp-Method': method, ...(params.name ? { 'Mcp-Name': params.name } : {}) },
    body: JSON.stringify({ jsonrpc: '2.0', id, method, params }) })).json());
  const created = await rpc(2, 'tools/call', { name: 'moodle_explorer', arguments: {}, task: { ttl: 60000 } });
  check('[http legacy] tools/call as task', created.result?.task?.status === 'working', JSON.stringify(created).slice(0, 200));
  const done = await rpc(3, 'tasks/result', { taskId: created.result?.task?.taskId });
  check('[http legacy] tasks/result returns the tool result', Array.isArray(done.result?.content), JSON.stringify(done).slice(0, 200));
}

// UI bridge: act through real Moodle pages as each user (nested loopback requests through the dev server).
async function bridge() {
  const teacher = await connect('v2', 'auto', 'teacher');
  const student = await connect('v2', 'auto', 'student');
  const admin = await connect('v2', 'auto', 'admin');
  const text = (r) => (r.content ?? []).filter((b) => b.type === 'text').map((b) => b.text).join('\n');
  const names = (await teacher.listTools()).tools.map((t) => t.name);
  check('[bridge] tools listed', ['moodle_page_view', 'moodle_page_action', 'moodle_page_submit'].every((n) => names.includes(n)));

  const course = await teacher.callTool({ name: 'moodle_page_view', arguments: { url: `/course/view.php?id=${seed.courseid}` } });
  const page = sc(course);
  check('[bridge] teacher views the course page', !course.isError && /E2E Biology/.test(page.title ?? ''), text(course).slice(0, 300));
  const pagelink = (page.links ?? []).find((l) => /mod\/page\/view\.php/.test(l.url));
  check('[bridge] course page lists the page activity link', Boolean(pagelink), JSON.stringify(page.links ?? []).slice(0, 300));
  check('[bridge] sesskey never leaves the server', !/sesskey=[A-Za-z0-9]{10}/.test(JSON.stringify(course)));

  const cmid = pagelink ? new URL(pagelink.url).searchParams.get('id') : '0';
  const edit = await teacher.callTool({ name: 'moodle_page_view', arguments: { url: `/course/modedit.php?update=${cmid}` } });
  const form = (sc(edit).forms ?? []).find((f) => (f.fields ?? []).some((x) => x.name === 'name'));
  check('[bridge] activity settings form parsed', Boolean(form) && form.fields.find((x) => x.name === 'name').value === 'Welcome page',
    text(edit).slice(0, 300));
  const saved = await teacher.callTool({ name: 'moodle_page_submit', arguments: {
    url: `/course/modedit.php?update=${cmid}`, form: form?.id ?? 'F1', fields: { name: 'Welcome page (edited)' } } });
  check('[bridge] form submitted without validation errors', !saved.isError
    && !(sc(saved).alerts ?? []).some((a) => a.type === 'error'), text(saved).slice(0, 400));
  const contents = sc(await teacher.callTool({ name: 'wrapper_moodle_api_execute', arguments: {
    functionname: 'core_course_get_contents', params: { courseid: seed.courseid } } }));
  check('[bridge] rename really saved in Moodle', JSON.stringify(contents).includes('Welcome page (edited)'));

  const sesskeyview = await teacher.callTool({ name: 'moodle_page_view', arguments: {
    url: `/course/mod.php?hide=${cmid}&sesskey={sesskey}` } });
  check('[bridge] state-changing link refused by page_view', sesskeyview.isError && /moodle_page_action/.test(text(sesskeyview)));
  const hide = await teacher.callTool({ name: 'moodle_page_action', arguments: { url: `/course/mod.php?hide=${cmid}&sesskey={sesskey}` } });
  const after = sc(await teacher.callTool({ name: 'wrapper_moodle_api_execute', arguments: {
    functionname: 'core_course_get_course_module', params: { cmid: Number(cmid) } } }));
  check('[bridge] action link hid the activity', !hide.isError && Number((after.result ?? after).data?.cm?.visible) === 0,
    `${text(hide).slice(0, 200)} ${JSON.stringify(after).slice(0, 200)}`);

  const denied = await student.callTool({ name: 'moodle_page_view', arguments: { url: `/course/edit.php?id=${seed.courseid}` } });
  check('[bridge] student refused the course settings page by Moodle', /permission|not allowed|cannot/i.test(text(denied)),
    text(denied).slice(0, 300));

  for (const url of ['/login/logout.php', '/webservice/mcp/server.php', '/admin/tool/mobile/autologin.php']) {
    const r = await admin.callTool({ name: 'moodle_page_view', arguments: { url } });
    check(`[bridge] policy refuses ${url}`, r.isError && /uibridgeurldenied/.test(text(r) + JSON.stringify(r._meta ?? {})), text(r).slice(0, 200));
  }
  const offsite = await admin.callTool({ name: 'moodle_page_view', arguments: { url: 'https://example.com/' } });
  check('[bridge] policy refuses other sites', offsite.isError, text(offsite).slice(0, 200));

  const settings = await admin.callTool({ name: 'moodle_page_view', arguments: { url: '/admin/settings.php?section=sitepolicies' } });
  const adminform = (sc(settings).forms ?? []).find((f) => (f.fields ?? []).some((x) => x.name === 's__maxeditingtime'));
  check('[bridge] admin settings page parsed', Boolean(adminform), text(settings).slice(0, 300));
  const changed = await admin.callTool({ name: 'moodle_page_submit', arguments: { url: '/admin/settings.php?section=sitepolicies',
    form: adminform?.id ?? 'F1', fields: { s__maxeditingtime: '2700' } } });
  const reread = sc(await admin.callTool({ name: 'moodle_page_view', arguments: { url: '/admin/settings.php?section=sitepolicies' } }));
  const value = (reread.forms ?? []).flatMap((f) => f.fields ?? []).find((x) => x.name === 's__maxeditingtime')?.value;
  check('[bridge] admin setting changed through the page', !changed.isError && String(value) === '2700', `${text(changed).slice(0, 200)} value=${value}`);

  const csv = await teacher.callTool({ name: 'moodle_page_view', arguments: {
    url: `/report/log/index.php?id=${seed.courseid}&chooselog=1&logreader=logstore_standard&download=csv` } });
  check('[bridge] report export returned as a saved file', !csv.isError && /^moodle:\/\/file\//.test(sc(csv).file?.uri ?? ''),
    text(csv).slice(0, 300));

  await Promise.all([teacher.close(), student.close(), admin.close()]);
}

for (const [sdk, mode] of [['v1', 'legacy'], ['v2', 'legacy'], ['v2', 'auto'], ['v2', 'pin']]) {
  await surfaces(sdk, mode);
}
await files();
await attempt('[cold] all tools', coldcalls);
await attempt('[http] raw checks', raw);
await attempt('[bridge] UI bridge', bridge);

console.log(failures === 0 ? '\nALL LIVE CHECKS PASSED' : `\n${failures} LIVE CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
