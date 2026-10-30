-- DRAFT DDL BLUEPRINT, not an installed migration or application entrypoint.
-- Apply only in a disposable schema-8 fixture for review.
-- The future migration driver owns BEGIN IMMEDIATE, shape validation,
-- foreign-key/integrity verification, the schema-9 stamp, and COMMIT.
-- No runtime constant, existing table, schema stamp, or external file changes here.

CREATE TABLE IF NOT EXISTS api_security_state (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    bound_epoch TEXT CHECK (
        bound_epoch IS NULL OR
        (length(bound_epoch) = 64 AND bound_epoch NOT GLOB '*[^0-9a-f]*')
    ),
    CHECK (enabled = 0 OR bound_epoch IS NOT NULL)
) STRICT;

INSERT INTO api_security_state(singleton, enabled, bound_epoch)
VALUES (1, 0, NULL)
ON CONFLICT(singleton) DO NOTHING;

CREATE TABLE IF NOT EXISTS api_tokens (
    id TEXT PRIMARY KEY NOT NULL CHECK (
        length(id) = 32 AND id NOT GLOB '*[^0-9a-f]*'
    ),
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    issued_by INTEGER NOT NULL,
    name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 80),
    secret_hash TEXT NOT NULL UNIQUE CHECK (
        length(secret_hash) = 64 AND secret_hash NOT GLOB '*[^0-9a-f]*'
    ),
    credential_epoch TEXT NOT NULL CHECK (
        length(credential_epoch) = 64 AND credential_epoch NOT GLOB '*[^0-9a-f]*'
    ),
    created_at INTEGER NOT NULL DEFAULT (unixepoch()),
    expires_at INTEGER NOT NULL,
    revoked_at INTEGER,
    revoke_reason TEXT CHECK (revoke_reason IS NULL OR revoke_reason IN (
        'manual', 'account_change', 'api_disabled', 'epoch_rotation',
        'backup_snapshot', 'restore'
    )),
    last_used_at INTEGER,
    CHECK (expires_at > created_at AND expires_at <= created_at + 7776000),
    CHECK (revoked_at IS NULL OR revoked_at >= created_at),
    CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL)),
    CHECK (last_used_at IS NULL OR last_used_at >= created_at)
) STRICT;

CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_api_tokens_expiry ON api_tokens(expires_at);

CREATE TABLE IF NOT EXISTS api_token_scopes (
    token_id TEXT NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,
    scope TEXT NOT NULL CHECK (scope IN (
        'posts:read', 'posts:write', 'posts:publish',
        'pages:read', 'pages:write', 'pages:publish'
    )),
    PRIMARY KEY (token_id, scope)
) STRICT;

CREATE TABLE IF NOT EXISTS api_mutation_receipts (
    token_id TEXT NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,
    key_hash TEXT NOT NULL CHECK (
        length(key_hash) = 64 AND key_hash NOT GLOB '*[^0-9a-f]*'
    ),
    request_hash TEXT NOT NULL CHECK (
        length(request_hash) = 64 AND request_hash NOT GLOB '*[^0-9a-f]*'
    ),
    resource_kind TEXT NOT NULL CHECK (resource_kind IN ('post', 'page')),
    operation TEXT NOT NULL CHECK (operation IN ('create', 'update_draft', 'publish')),
    resource_id INTEGER NOT NULL CHECK (resource_id > 0),
    committed_revision INTEGER NOT NULL CHECK (committed_revision > 0),
    committed_status TEXT NOT NULL CHECK (committed_status IN ('draft', 'published')),
    mutation_id TEXT NOT NULL UNIQUE CHECK (length(mutation_id) = 36),
    http_status INTEGER NOT NULL CHECK (http_status IN (200, 201)),
    response_json TEXT,
    location TEXT CHECK (
        location IS NULL OR (
            length(location) BETWEEN 1 AND 2048 AND
            substr(location, 1, 1) = '/' AND substr(location, 1, 2) <> '//'
        )
    ),
    state TEXT NOT NULL DEFAULT 'replayable' CHECK (state IN ('replayable', 'expired')),
    created_at INTEGER NOT NULL DEFAULT (unixepoch()),
    replay_until INTEGER NOT NULL,
    PRIMARY KEY (token_id, key_hash),
    CHECK (replay_until = created_at + 86400),
    CHECK ((operation = 'create' AND http_status = 201) OR
           (operation <> 'create' AND http_status = 200)),
    CHECK ((operation = 'publish' AND committed_status = 'published') OR
           (operation <> 'publish' AND committed_status = 'draft')),
    CHECK ((state = 'replayable' AND response_json IS NOT NULL) OR
           (state = 'expired' AND response_json IS NULL AND location IS NULL)),
    CHECK (response_json IS NULL OR CASE WHEN json_valid(response_json) THEN
        json_type(response_json) = 'object' AND
        length(CAST(response_json AS BLOB)) <= 4096 AND
        COALESCE(
            json_type(response_json, '$.id') = 'integer' AND
            json_extract(response_json, '$.id') = resource_id AND
            json_extract(response_json, '$.type') = resource_kind AND
            json_type(response_json, '$.revision') = 'integer' AND
            json_extract(response_json, '$.revision') = committed_revision AND
            json_extract(response_json, '$.status') = committed_status AND
            json_extract(response_json, '$.mutation_id') = mutation_id AND
            json_type(response_json, '$.slug') = 'text' AND
            json_type(response_json, '$.url') IN ('text', 'null'),
            0
        )
    ELSE 0 END)
) STRICT;

CREATE INDEX IF NOT EXISTS idx_api_receipts_cleanup
    ON api_mutation_receipts(state, replay_until);

CREATE TABLE IF NOT EXISTS api_rate_windows (
    budget_key TEXT PRIMARY KEY NOT NULL CHECK (
        length(budget_key) = 64 AND budget_key NOT GLOB '*[^0-9a-f]*'
    ),
    budget_kind TEXT NOT NULL CHECK (budget_kind IN ('peer_auth', 'token_read', 'token_write')),
    used INTEGER NOT NULL CHECK (used BETWEEN 1 AND 60),
    window_start INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    CHECK (expires_at = window_start + 60),
    CHECK (budget_kind <> 'token_write' OR used <= 20)
) STRICT;

CREATE INDEX IF NOT EXISTS idx_api_rate_expiry ON api_rate_windows(expires_at);
