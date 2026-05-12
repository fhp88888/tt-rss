alter table ttrss_entries add column ai_summary text;
alter table ttrss_entries add column ai_summary_content_hash varchar(250);
alter table ttrss_entries add column ai_summary_generated_at timestamp;
