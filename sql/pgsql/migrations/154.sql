ALTER TABLE ttrss_feeds ADD COLUMN ai_digest_enabled boolean NOT NULL DEFAULT false;

CREATE TABLE ttrss_ai_digests (
    feed_id integer NOT NULL REFERENCES ttrss_feeds(id) ON DELETE CASCADE,
    owner_uid integer NOT NULL REFERENCES ttrss_users(id) ON DELETE CASCADE,
    content jsonb NOT NULL,
    generated_at timestamp NOT NULL DEFAULT now(),
    PRIMARY KEY (feed_id, owner_uid)
);

CREATE INDEX ttrss_ai_digests_generated_at_idx ON ttrss_ai_digests(generated_at);
