create table ttrss_ai_summary_queue (
	owner_uid integer not null references ttrss_users(id) ON DELETE CASCADE,
	ref_id integer not null references ttrss_entries(id) ON DELETE CASCADE,
	content_hash varchar(250) not null,
	queued_at timestamp not null default NOW(),
	attempts integer not null default 0,
	last_error text,
	primary key (owner_uid, ref_id));

create index ttrss_ai_summary_queue_owner_uid_idx on ttrss_ai_summary_queue(owner_uid);
create index ttrss_ai_summary_queue_queued_at_idx on ttrss_ai_summary_queue(queued_at);
