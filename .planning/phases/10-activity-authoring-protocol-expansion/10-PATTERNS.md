# Phase 10: Activity Authoring & Protocol Expansion - Pattern Map

**Mapped:** 2026-05-01
**Files analyzed:** 13
**Analogs found:** 13 / 13

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `db/install.xml` | migration | DB schema | `db/install.xml` | exact |
| `db/upgrade.php` | migration | DB schema | `db/upgrade.php` | exact |
| `version.php` | config | config | `version.php` | exact |
| `classes/local/transport/server.php` | controller | request-response | `classes/local/transport/server.php` | exact |
| `classes/local/tool_provider.php` | provider | list tools | `classes/local/tool_provider.php` | exact |
| `classes/local/wrapper/discovery_service.php` | service | query API | `classes/local/wrapper/badge_service.php` | role-match |
| `classes/local/wrapper/memory_service.php` | service | CRUD | `classes/local/wrapper/badge_service.php` | role-match |
| `classes/local/wrapper/activity_service.php` | service | activity authoring | `classes/local/wrapper/badge_service.php` | role-match |
| `classes/local/wrapper/manager.php` | config | registry | `classes/local/wrapper/manager.php` | exact |
| `tests/transport_server_test.php` | test | test | `tests/transport_server_test.php` | exact |
| `tests/discovery_service_test.php` | test | test | `tests/parity_wrapper_services_test.php` | role-match |
| `tests/memory_service_test.php` | test | test | `tests/parity_wrapper_services_test.php` | role-match |
| `tests/activity_service_test.php` | test | test | `tests/parity_wrapper_services_test.php` | role-match |

## Pattern Assignments

### `db/install.xml` (migration, DB schema)

**Analog:** `db/install.xml`

**Table creation pattern** (lines 4-33):
```xml
    <TABLE NAME="webservice_mcp_credential" COMMENT="Plugin-managed connector credentials for Moodle MCP bootstrap and remote access.">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="userid_fk" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
      </KEYS>
    </TABLE>
```

---

### `db/upgrade.php` (migration, DB schema)

**Analog:** `db/upgrade.php`

**Table upgrade pattern** (lines 33-60):
```php
    if ($oldversion < 2026042103) {
        $table = new xmldb_table('webservice_mcp_credential');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            // ...
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026042103, 'webservice', 'mcp');
    }
```

---

### `classes/local/transport/server.php` (controller, request-response)

**Analog:** `classes/local/transport/server.php`

**Routing pattern** (lines 674-689):
```php
    protected function handle_transport_method(): void {
        // ...
        switch ($this->mcprequest->method) {
            case 'tools/list':
                if (!$this->ensure_oauth_scope(false)) {
                    return;
                }
                $this->send_tools_list_response();
                return;
```

**JSON-RPC Response Pattern** (lines 426-464):
```php
    protected function send_tools_list_response(): void {
        $result = // ...

        $payload = [
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ];

        $this->set_status(200);
        $this->record_transport_event((string)$this->transportrequest['sessionid'], $payload);
        $this->emit($this->safe_json_encode($payload));
    }
```

---

### `classes/local/wrapper/discovery_service.php` & `activity_service.php` & `memory_service.php` (service)

**Analog:** `classes/local/wrapper/badge_service.php`

**Service Class Pattern** (lines 28-57):
```php
namespace webservice_mcp\local\wrapper;

use context;
use core_external\external_api;
use stdClass;

class badge_service {
    public function create_badge(array $payload, ?int $courseid = null): array {
        global $PAGE;
        require_once($this->libdir() . '/badgeslib.php');

        $context = $this->creation_context($courseid);
        external_api::validate_context($context);
        \require_capability('moodle/badges:createbadge', $context);
        // ...
        return $this->badge_result($badge);
    }
}
```

---

### `classes/local/wrapper/manager.php` (config, registry)

**Analog:** `classes/local/wrapper/manager.php`

**Registry execution pattern** (lines 110-116):
```php
    public function execute(string $name, array $arguments, context $restrictedcontext, ?stdClass $user = null): array {
        // ...
        return match ($name) {
            'wrapper_course_add_section_after' => $this->courseauthoringservice->add_section_after(
                (int)($arguments['courseid'] ?? 0),
                isset($arguments['targetsectionid']) ? (int)$arguments['targetsectionid'] : null
            ),
        // ...
```

**Registry definition pattern** (lines 423-441):
```php
            new definition(
                'wrapper_badge_create_badge',
                'webservice_mcp',
                'operator',
                'Create a site or course badge through Moodle’s native badge model.',
                ['moodle/badges:createbadge'],
                [
                    'type' => 'object',
                    'properties' => [
                        'courseid' => ['type' => 'number'],
                        'payload' => ['type' => 'object'],
                    ],
                    'required' => ['payload'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'badgeid' => ['type' => 'number'],
                        'name' => ['type' => 'string'],
                        'status' => ['type' => 'number'],
                    ],
                ],
            ),
```

---

### `tests/discovery_service_test.php`, `tests/memory_service_test.php`, `tests/activity_service_test.php` (test)

**Analog:** `tests/parity_wrapper_services_test.php`

**Test class pattern** (lines 28-44):
```php
namespace webservice_mcp;

use advanced_testcase;
use context_system;
use core_external\external_api;

class parity_wrapper_services_test extends advanced_testcase {
    public function test_question_bank_service_can_manage_supported_parity_flows(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        external_api::set_context_restriction(null);

        $service = new question_bank_service();
        $systemcontext = context_system::instance();
        // ...
        $this->assertSame('shortanswer', $createdquestion['qtype']);
    }
}
```

## Shared Patterns

### Context Validation & Capability Checks
**Source:** `classes/local/wrapper/badge_service.php`
**Apply to:** All service wrapper files (`discovery_service.php`, `memory_service.php`, `activity_service.php`)
```php
        external_api::validate_context($context);
        \require_capability('moodle/capabilities:here', $context);
```

### Dependency Injection for Services
**Source:** `classes/local/wrapper/manager.php`
**Apply to:** `classes/local/wrapper/manager.php` (injecting new services)
```php
    public function __construct(
        // ...
        ?badge_service $badgeservice = null
    ) {
        // ...
        $this->badgeservice = $badgeservice ?? new badge_service();
    }
```

## No Analog Found

N/A - All components have direct structural analogues within the codebase.

## Metadata

**Analog search scope:** `db/`, `classes/local/transport/`, `classes/local/wrapper/`, `tests/`
**Files scanned:** 6
**Pattern extraction date:** 2026-05-01