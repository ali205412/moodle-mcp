# Activity Authoring & Protocol Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand the Moodle MCP connector to fully manage activity lifecycles (authoring and reporting) using internal `locallib.php` classes, solve context exhaustion by introducing a Tool Discovery Engine, and turn the connector into a persistent knowledgebase via MCP Resources and Prompts.

**Architecture:** 
1. Database migration for `webservice_mcp_memory`.
2. Expand `transport/server.php` to handle `resources/*` and `prompts/*` JSON-RPC methods.
3. Build new Parity Wrappers for Activity Authoring, Reporting, Tool Discovery, and Memory Saving.
4. Filter out raw Moodle functions from `tools/list` to rely entirely on the new Discovery engine.

**Tech Stack:** PHP 8.2, Moodle 4.5 APIs, MCP JSON-RPC Spec.

---

### Task 1: Database Migration for MCP Memory

**Files:**
- Modify: `db/install.xml`
- Modify: `db/upgrade.php`
- Modify: `version.php`

- [ ] **Step 1: Write the XML schema**
Modify `db/install.xml` to add the `webservice_mcp_memory` table.
```xml
    <TABLE NAME="webservice_mcp_memory" COMMENT="Persistent knowledgebase for MCP sessions">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="keyname" TYPE="char" LENGTH="255" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="content" TYPE="text" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="userid_fk" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
        <KEY NAME="uniq_user_key" TYPE="unique" FIELDS="userid, keyname"/>
      </KEYS>
    </TABLE>
```

- [ ] **Step 2: Write the upgrade step**
Modify `db/upgrade.php` to include the table creation block.
```php
        if ($oldversion < 2026042224) {
            $table = new xmldb_table('webservice_mcp_memory');
            // ... add fields, keys, and create_table
            upgrade_plugin_savepoint(true, 2026042224, 'webservice', 'mcp');
        }
```

- [ ] **Step 3: Bump the version**
Modify `version.php` to bump the version to `2026042224` to trigger the upgrade.

- [ ] **Step 4: Run local DB upgrade to verify**
Run: `bash scripts/run-local-tests.sh` to let the CI installer run the upgrade and ensure no XML errors exist.

- [ ] **Step 5: Commit**
```bash
git add db/install.xml db/upgrade.php version.php
git commit -m "feat(mcp): add memory database table for knowledgebase"
```

---

### Task 2: Implement MCP Resources Protocol

**Files:**
- Modify: `classes/local/transport/server.php`
- Test: `tests/transport_server_test.php`

- [ ] **Step 1: Write the failing tests**
Add `test_transport_server_handles_resources_list` and `test_transport_server_handles_resources_read` to `transport_server_test.php`.
```php
    public function test_transport_server_handles_resources_list(): void {
        // Assert jsonrpc 'resources/list' returns the moodle://user/memory resource.
    }
```

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --filter test_transport_server_handles_resources_list tests/transport_server_test.php`
Expected: FAIL due to 'Method not found'.

- [ ] **Step 3: Write minimal implementation**
Modify `classes/local/transport/server.php` `handle_transport_method` to route `resources/list` and `resources/read`.
Add `send_resources_list_response()` and `send_resources_read_response()` methods.
For `resources/list`, return:
```php
        $result = [
            'resources' => [
                [
                    'uri' => 'moodle://user/memory',
                    'name' => 'User Memory',
                    'mimeType' => 'text/markdown',
                ]
            ]
        ];
```
For `resources/read`, fetch from `webservice_mcp_memory`.

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --filter test_transport_server_handles_resources_ tests/transport_server_test.php`
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add classes/local/transport/server.php tests/transport_server_test.php
git commit -m "feat(mcp): implement resources protocol namespace"
```

---

### Task 3: Implement MCP Prompts Protocol

**Files:**
- Modify: `classes/local/transport/server.php`
- Test: `tests/transport_server_test.php`

- [ ] **Step 1: Write the failing test**
Add `test_transport_server_handles_prompts_list` and `test_transport_server_handles_prompts_get`.

- [ ] **Step 2: Run test to verify it fails**
Run: `vendor/bin/phpunit --filter test_transport_server_handles_prompts_ tests/transport_server_test.php`
Expected: FAIL due to 'Method not found'.

- [ ] **Step 3: Write minimal implementation**
Modify `handle_transport_method` to route `prompts/list` and `prompts/get`.
Add `moodle_course_architect` and `moodle_grader_assistant` prompts.

- [ ] **Step 4: Run test to verify it passes**
Run: `vendor/bin/phpunit --filter test_transport_server_handles_prompts_ tests/transport_server_test.php`
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add classes/local/transport/server.php tests/transport_server_test.php
git commit -m "feat(mcp): implement prompts protocol namespace"
```

---

### Task 4: Tool Discovery Engine

**Files:**
- Create: `classes/local/wrapper/discovery_service.php`
- Modify: `classes/local/tool_provider.php`
- Test: `tests/discovery_service_test.php`

- [ ] **Step 1: Hide Raw Functions from `tools/list`**
Modify `classes/local/tool_provider.php` `list_tools_for_service_ids()`. Instead of returning the full `$alltools` array, return *only* the `$wrappertools`. The native Moodle APIs must no longer be broadcast to prevent context exhaustion.

- [ ] **Step 2: Write failing tests for the Discovery Wrapper**
Create `tests/discovery_service_test.php` testing `wrapper_moodle_api_search` and `wrapper_moodle_api_execute`.

- [ ] **Step 3: Write minimal implementation**
Create `classes/local/wrapper/discovery_service.php`.
```php
class discovery_service {
    public function search_api(string $keyword): array { ... }
    public function execute_api(string $functionname, array $params): array { ... }
}
```
Register these wrappers in `classes/local/wrapper/manager.php`.

- [ ] **Step 4: Run tests to verify**
Run: `vendor/bin/phpunit tests/discovery_service_test.php`
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add classes/local/wrapper/discovery_service.php classes/local/tool_provider.php classes/local/wrapper/manager.php tests/discovery_service_test.php
git commit -m "feat(mcp): implement tool discovery engine and hide raw APIs"
```

---

### Task 5: Memory Writer Wrapper

**Files:**
- Create: `classes/local/wrapper/memory_service.php`
- Modify: `classes/local/wrapper/manager.php`
- Test: `tests/memory_service_test.php`

- [ ] **Step 1: Write the failing test**
Create `tests/memory_service_test.php` testing `wrapper_user_memory_save`.

- [ ] **Step 2: Run test to verify it fails**
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**
Create `classes/local/wrapper/memory_service.php`.
```php
class memory_service {
    public function save_memory(string $keyname, string $content): array { ... }
}
```
Register it in `manager.php`.

- [ ] **Step 4: Run tests to verify**
Run: `vendor/bin/phpunit tests/memory_service_test.php`
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add classes/local/wrapper/memory_service.php classes/local/wrapper/manager.php tests/memory_service_test.php
git commit -m "feat(mcp): add user memory writer wrapper"
```

---

### Task 6: Internal Class Wrappers for Authoring & Reporting (The Big 5)

**Files:**
- Create: `classes/local/wrapper/activity_service.php`
- Modify: `classes/local/wrapper/manager.php`
- Test: `tests/activity_service_test.php`

- [ ] **Step 1: Write the failing test**
Create `tests/activity_service_test.php` testing `wrapper_course_add_module` and `wrapper_assign_read_data`.

- [ ] **Step 2: Run test to verify it fails**
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**
Create `classes/local/wrapper/activity_service.php`.
Implement the `course_add_module` logic (simulating moodleform submission to `instance_add`).
Implement the `assign_read_data` logic (instantiating `\assign` and calling `get_submissions()`).
Register them in `manager.php`.

- [ ] **Step 4: Run tests to verify**
Run: `vendor/bin/phpunit tests/activity_service_test.php`
Expected: PASS

- [ ] **Step 5: Commit**
```bash
git add classes/local/wrapper/activity_service.php classes/local/wrapper/manager.php tests/activity_service_test.php
git commit -m "feat(mcp): implement activity authoring and reporting internal wrappers"
```
