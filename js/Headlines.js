'use strict';

/* global __, ngettext, Article, App */
/* global PluginHost, Notify, xhr, Feeds */
/* global CommonDialogs */

const Headlines = {
	vgroup_last_feed: undefined,
	_headlines_scroll_timeout: 0,
	//_observer_counters_timeout: 0,
	headlines: [],
	current_first_id: 0,
	_scroll_reset_timeout: false,
	default_force_previous: false,
	default_force_to_top: false,
	line_scroll_offset: 120, /* px */
	row_observer: new MutationObserver((mutations) => {
		const modified = [];

		mutations.forEach((m) => {
			if (m.type === 'attributes' && ['class', 'data-score'].indexOf(m.attributeName) !== -1) {

				const row = m.target;
				const id = parseInt(row.getAttribute('data-article-id'));

				if (Headlines.headlines[id]) {
					const hl = Headlines.headlines[id];

					if (hl) {
						const hl_old = {...{}, ...hl};

						hl.unread = row.classList.contains('Unread');
						hl.marked = row.classList.contains('marked');
						hl.published = row.classList.contains('published');

						hl.active = row.classList.contains('active');

						hl.score = row.getAttribute('data-score');

						modified.push({id: hl.id, new: hl, old: hl_old, row: row});
					}
				}
			}
		});

		PluginHost.run(PluginHost.HOOK_HEADLINE_MUTATIONS, mutations);

		window.requestIdleCallback(() => {
			Headlines.syncModified(modified);
		});
	}),
	syncModified: function (modified) {
		const ops = {
			tmark: [],
			tpub: [],
			read: [],
			unread: [],
			activate: [],
			deactivate: [],
			rescore: {},
		};

		modified.forEach(function (m) {
			if (m.old.marked !== m.new.marked)
				ops.tmark.push(m.id);

			if (m.old.published !== m.new.published)
				ops.tpub.push(m.id);

			if (m.old.unread !== m.new.unread)
				m.new.unread ? ops.unread.push(m.id) : ops.read.push(m.id);

			if (m.old.active !== m.new.active)
				m.new.active ? ops.activate.push(m.row) : ops.deactivate.push(m.row);

			if (m.old.score !== m.new.score) {
				const score = m.new.score;

				ops.rescore[score] = ops.rescore[score] || [];
				ops.rescore[score].push(m.id);
			}
		});

		const promises = [];

		if (ops.tmark.length !== 0)
			promises.push(xhr.post("backend.php",
				{op: "RPC", method: "markSelected", "ids[]": ops.tmark, cmode: 2}));

		if (ops.tpub.length !== 0)
			promises.push(xhr.post("backend.php",
				{op: "RPC", method: "publishSelected", "ids[]": ops.tpub, cmode: 2}));

		if (ops.read.length !== 0)
			promises.push(xhr.post("backend.php",
				{op: "RPC", method: "catchupSelected", "ids[]": ops.read, cmode: 0}));

		if (ops.unread.length !== 0)
			promises.push(xhr.post("backend.php",
				{op: "RPC", method: "catchupSelected", "ids[]": ops.unread, cmode: 1}));

		const scores = Object.keys(ops.rescore);

		if (scores.length !== 0) {
			scores.forEach((score) => {
				promises.push(xhr.post("backend.php",
					{op: "Article", method: "setScore", "ids[]": ops.rescore[score], score: score}));
			});
		}

		Promise.allSettled(promises).then((results) => {
			let feeds = [];
			let labels = [];

			results.forEach((res) => {
				if (res) {
					try {
						const obj = JSON.parse(res.value);

						if (obj.feeds)
							feeds = feeds.concat(obj.feeds);

						if (obj.labels)
							labels = labels.concat(obj.labels);

					} catch (e) {
						console.warn('Error parsing mutation result:', e, res);
					}
				}
			});

			if (feeds.length > 0) {
				Feeds.requestCounters(feeds, labels);
			}

			PluginHost.run(PluginHost.HOOK_HEADLINE_MUTATIONS_SYNCED, results);
		});
	},
	click: function (event, id) {
		if (event.altKey) {
			Article.openInNewWindow(id);
			Headlines.toggleUnread(id, 0);
		} else {
			Article.view(id);
		}

		return false;
	},
	initScrollHandler: function () {
		document.getElementById("headlines-frame").onscroll = (event) => {
			clearTimeout(this._headlines_scroll_timeout);
			this._headlines_scroll_timeout = window.setTimeout(function () {
				//console.log('done scrolling', event);
				Headlines.scrollHandler(event);
			}, 50);
		}
	},
	loadMore: function () {
		const view_mode = Feeds.getToolbarValues().view_mode;
		const unread_in_buffer = document.querySelectorAll('#headlines-frame > div[id*=RROW][class*=Unread]').length;
		const num_all = document.querySelectorAll('#headlines-frame > div[id*=RROW]').length;
		const num_unread = Feeds.getUnread(Feeds.getActive(), Feeds.activeIsCat());

		// TODO implement marked & published

		let offset = num_all;

		switch (view_mode) {
			case "marked":
			case "published":
				console.warn('loadMore:', view_mode, 'not implemented');
				break;
			case "unread":
				if (!(Feeds.getActive() === Feeds.FEED_RECENTLY_READ && !Feeds.activeIsCat()))
					offset = unread_in_buffer;
				break;
			case "adaptive":
				if (!(Feeds.getActive() === Feeds.FEED_STARRED && !Feeds.activeIsCat()))
					offset = num_unread > 0 ? unread_in_buffer : num_all;
				break;
		}

		console.log("loadMore, offset=", offset);

		Feeds.open({feed: Feeds.getActive(), is_cat: Feeds.activeIsCat(), offset: offset, append: true});
	},
	isChildVisible: function (elem) {
		return App.Scrollable.isChildVisible(elem, document.getElementById("headlines-frame"));
	},
	firstVisible: function () {
		const rows = document.querySelectorAll('#headlines-frame > div[id*=RROW]');

		for (let i = 0; i < rows.length; i++) {
			const row = rows[i];

			if (this.isChildVisible(row)) {
				return parseInt(row.getAttribute('data-article-id'));
			}
		}
	},
	scrollHandler: function (/*event*/) {
		try {
			if (!Feeds.infscroll_disabled && !Feeds.infscroll_in_progress) {
				const hsp = document.getElementById("headlines-spacer");
				const container = document.getElementById("headlines-frame");

				if (hsp && hsp.previousSibling) {
					const last_row = hsp.previousSibling;

					// invoke lazy load if last article in buffer is nearly visible OR is active
					if (Article.getActive() === parseInt(last_row.getAttribute('data-article-id'))
						|| last_row.offsetTop - 250 <= container.scrollTop + container.offsetHeight) {
						hsp.innerHTML = `<span class='text-muted text-small text-center'><img class="icon-three-dots" src="${App.getInitParam('icon_three_dots')}"> ${__("Loading, please wait...")}</span>`;

						Headlines.loadMore();
						return;
					}
				}
			}

			PluginHost.run(PluginHost.HOOK_HEADLINES_SCROLL_HANDLER);

		} catch (e) {
			console.error('scrollHandler error:', e);
		}
	},
	objectById: function (id) {
		return this.headlines[id];
	},
	setCommonClasses: function (headlines_count) {
		const container = document.getElementById("headlines-frame");

		container.classList.remove('normal');

		container.classList.add('normal');
		container.setAttribute("data-headlines-count", parseInt(headlines_count));

		// for floating title because it's placed outside of headlines-frame
		document.getElementById('main').classList.remove('expandable', 'expanded');
	},
	renderAgain: function () {
		// TODO: wrap headline elements into a knockoutjs model to prevent all this stuff
		Headlines.setCommonClasses(this.headlines.filter((h) => h.id).length);

		document.querySelectorAll('#headlines-frame > div[id*=RROW]').forEach((row) => {
			const id = parseInt(row.getAttribute('data-article-id'));
			const hl = this.headlines[id];

			if (hl) {
				const new_row = this.render({}, hl);

				row.parentNode.replaceChild(new_row, row);

				if (hl.active) {
					new_row.classList.add('active');
					Article.unpack(new_row);

					Article.view(id);
				}
			}
		});

		dijit.byId('main').resize();

		PluginHost.run(PluginHost.HOOK_HEADLINES_RENDERED);
	},
	render: function (headlines, hl) {
		let row = null;
		const preview_class = hl.content_preview_is_ai ? "preview ai-summary" : "preview";
		const relative_time = this.formatRelativeTime(hl.updated_ts);
		const thumbnail = hl.flavor_image ?
			`<div class="headline-thumbnail">
				<img class="headline-thumbnail-image" alt="" loading="lazy"
					onload="Headlines.validateThumbnail(this)"
					onerror="this.closest('.headline-thumbnail').remove()"
					src="${App.escapeHtml(App.sanitizeUrl(hl.flavor_image))}">
			</div>` : "";

		let row_class = "";

		if (hl.marked) row_class += " marked";
		if (hl.published) row_class += " published";
		if (hl.unread) row_class += " Unread";
		if (headlines.vfeed_group_enabled) row_class += " vgrlf";

		if (headlines.vfeed_group_enabled && hl.feed_title && this.vgroup_last_feed !== hl.feed_id) {
			const vgrhdr = `<div data-feed-id='${hl.feed_id}' class='feed-title'>
									<div class="pull-right icon-feed" title="${App.escapeHtml(hl.feed_title)}"
										onclick="Feeds.open({feed:${hl.feed_id}})">${Feeds.renderIcon(hl.feed_id, hl.has_icon)}</div>
									<a class="title" title="${__('Open site')}" target="_blank" rel="noopener noreferrer" href="${App.escapeHtml(App.sanitizeUrl(hl.site_url))}">${hl.feed_title}</a>
									<a class="catchup" title="${__('mark feed as read')}" onclick="Feeds.catchupFeedInGroup(${hl.feed_id})" href="#">
										<i class="icon-done material-icons">done_all</i>
									</a>
								</div>`

			const tmp = document.createElement("div");
			tmp.innerHTML = vgrhdr;

			document.getElementById("headlines-frame").appendChild(tmp.firstChild);

			this.vgroup_last_feed = hl.feed_id;
		}

		row = `<div class="hl ${row_class} ${Article.getScoreClass(hl.score)}"
				id="RROW-${hl.id}"
				data-orig-feed-id="${hl.feed_id}"
				data-orig-feed-title="${App.escapeHtml(hl.feed_title)}"
				data-article-id="${hl.id}"
				data-score="${hl.score}"
				data-article-title="${App.escapeHtml(hl.title)}"
				onmouseover="Article.mouseIn(${hl.id})"
				onmouseout="Article.mouseOut(${hl.id})">
			<div class="left">
				<span onclick="Feeds.open({feed:${hl.feed_id}})" class="icon-feed source-icon" title="${App.escapeHtml(hl.feed_title)}">${Feeds.renderIcon(hl.feed_id, hl.has_icon)}</span>
			</div>
			<div onclick="return Headlines.click(event, ${hl.id})" class="title headline-main">
				<div class="headline-meta">
					<span class="feed-name">${App.escapeHtml(hl.feed_title)}</span>
					<span class="relative-time" title="${App.escapeHtml(hl.updated_long)}">${relative_time}</span>
				</div>
				<div class="headline-body">
					<div class="headline-text">
						<span data-article-id="${hl.id}" class="hl-content headline-title-line hlMenuAttach">
							<a class="title" href="${App.escapeHtml(App.sanitizeUrl(hl.link))}">${hl.title}</a>
							${Article.renderLabels(hl.id, hl.labels)}
						</span>
						<span class="${preview_class} headline-preview">${hl.content_preview}</span>
					</div>
					${thumbnail}
				</div>
			</div>
			</div>
		`;

		const tmp = document.createElement("div");
		tmp.innerHTML = row;
		dojo.parser.parse(tmp);

		this.row_observer.observe(tmp.firstChild, {attributes: true});

		PluginHost.run(PluginHost.HOOK_HEADLINE_RENDERED, tmp.firstChild);

		return tmp.firstChild;
	},
	validateThumbnail: function (img) {
		if (img.naturalWidth < 160 || img.naturalHeight < 90) {
			img.closest('.headline-thumbnail').remove();
		}
	},
	formatRelativeTime: function (timestamp) {
		const ts = parseInt(timestamp);

		if (!ts) return "";

		const elapsed_seconds = Math.max(0, Math.floor(Date.now() / 1000) - ts);
		const elapsed_minutes = Math.floor(elapsed_seconds / 60);

		if (elapsed_minutes < 1) return "just now";
		if (elapsed_minutes === 1) return "1 minute ago";
		if (elapsed_minutes < 60) return `${elapsed_minutes} minutes ago`;

		const elapsed_hours = Math.floor(elapsed_minutes / 60);

		if (elapsed_hours === 1) return "an hour ago";
		if (elapsed_hours < 24) return `${elapsed_hours} hours ago`;

		const elapsed_days = Math.floor(elapsed_hours / 24);

		if (elapsed_days === 1) return "a day ago";

		return `${elapsed_days} days ago`;
	},
	updateCurrentUnread: function () {
		if (document.getElementById("feed_current_unread")) {
			const feed_unread = Feeds.getUnread(Feeds.getActive(), Feeds.activeIsCat());

			if (feed_unread > 0 && !Element.visible("feeds-holder")) {
				document.getElementById("feed_current_unread").innerText = feed_unread;
				Element.show("feed_current_unread");
			} else {
				Element.hide("feed_current_unread");
			}
		}
	},
	updateToolbarArticleTitle: function (id) {
		const elem = document.getElementById("toolbar-active-title");

		if (!elem) return;

		const row = id ? document.getElementById(`RROW-${id}`) : null;
		const title = row?.getAttribute("data-article-title") || "";
		const max_length = 48;
		const chars = Array.from(title.trim());

		elem.innerText = chars.length > max_length ?
			`${chars.slice(0, max_length).join("")}...` : title;

		elem.setAttribute("title", title);
		elem.classList.toggle("visible", !!title);
	},
	renderToolbar: function(headlines) {

		const tb = headlines['toolbar'];
		const search_query = Feeds._search_query ? Feeds._search_query.query : "";
		const target = dijit.byId('toolbar-headlines');

		target.destroyDescendants();

		if (tb && typeof tb === 'object') {
			target.attr('innerHTML',
			`
				<span class='left'>
					<a href="#" title="${__("Show as feed")}"
						onclick='CommonDialogs.generatedFeed("${headlines.id}", ${headlines.is_cat}, ${JSON.stringify(search_query)})'>
						<i class='icon-syndicate material-icons'>rss_feed</i>
					</a>
					${tb.site_url ?
						`<a class="feed_title" target="_blank" href="${App.escapeHtml(App.sanitizeUrl(tb.site_url))}" title="${tb.last_updated}">${tb.title}</a>` :
							`${search_query ? `<a href="#" onclick="Feeds.search(); return false" class="feed_title" title="${App.escapeHtml(search_query)}">${tb.title}</a>
							<span class="cancel_search">(<a href="#" onclick="Feeds.cancelSearch(); return false">${__("Cancel search")}</a>)</span>` :
								`<span class="feed_title">${tb.title}</span>`}`}
					${tb.error ? `<i title="${App.escapeHtml(tb.error)}" class='material-icons icon-error'>error</i>` : ''}
					<span id='feed_current_unread' style='display: none'></span>
				</span>
				<span id='toolbar-active-title' class='toolbar-active-title'></span>
				<span class='right'>
					${tb.plugin_buttons}
				</span>
			`);
		} else {
			target.attr('innerHTML', '');
		}

		dojo.parser.parse(target.domNode);
		Headlines.updateToolbarArticleTitle(Article.getActive());
	},
	onLoaded: function (reply, offset, append) {
		let is_cat = false;
		let feed_id = false;

		if (reply) {

			is_cat = reply['headlines']['is_cat'];
			feed_id = reply['headlines']['id'];
			Feeds.last_search_query = reply['headlines']['search_query'];

			if (feed_id !== Feeds.FEED_ERROR && (feed_id !== Feeds.getActive() || is_cat !== Feeds.activeIsCat()))
				return;

			const headlines_count = reply['headlines-info']['count'];

			//this.vgroup_last_feed = reply['headlines-info']['vgroup_last_feed'];
			this.current_first_id = reply['headlines']['first_id'];

			if (!append) {
				Feeds.infscroll_disabled = parseInt(headlines_count) !== 30;

				// also called in renderAgain() after view mode switch
				Headlines.setCommonClasses(headlines_count);

				/** TODO: remove @deprecated */
				document.getElementById("headlines-frame").setAttribute("is-vfeed",
					reply['headlines']['is_vfeed'] ? 1 : 0);

				document.getElementById("headlines-frame").setAttribute("data-is-vfeed",
					reply['headlines']['is_vfeed'] ? "true" : "false");

				Article.setActive(0);

				try {
					const headlines_frame = document.getElementById('headlines-frame');
					headlines_frame.classList.remove('smooth-scroll');
					headlines_frame.scrollTop = 0;
					headlines_frame.classList.add('smooth-scroll');
				} catch (e) {
					console.error('Error resetting headlines scroll:', e);
				}

				this.headlines = [];
				this.vgroup_last_feed = undefined;

				/*dojo.html.set(document.getElementById("toolbar-headlines"),
					reply['headlines']['toolbar'],
					{parseContent: true});*/

				Headlines.renderToolbar(reply['headlines']);

				if (typeof reply['headlines']['content'] === 'string') {
					document.getElementById("headlines-frame").innerHTML = reply['headlines']['content'];
				} else {
					document.getElementById("headlines-frame").innerHTML = '';

					for (let i = 0; i < reply['headlines']['content'].length; i++) {
						const hl = reply['headlines']['content'][i];

						document.getElementById("headlines-frame").appendChild(this.render(reply['headlines'], hl));

						this.headlines[parseInt(hl.id)] = hl;
					}
				}

				let hsp = document.getElementById("headlines-spacer");

				if (!hsp) {
					hsp = document.createElement("div");
					hsp.id = "headlines-spacer";
				}

				// clear out hsp contents in case there's a power-hungry svg icon rotating there
				hsp.innerHTML = "";

				dijit.byId('headlines-frame').domNode.appendChild(hsp);

				this.initHeadlinesMenu();

				if (Feeds.infscroll_disabled)
					hsp.innerHTML = "<a href='#' onclick='Feeds.openNextUnread()'>" +
						__("Click to open next unread feed.") + "</a>";

				/*
				if (Feeds._search_query) {
					document.getElementById("feed_title").innerHTML += "<span id='cancel_search'>" +
						" (<a href='#' onclick='Feeds.cancelSearch()'>" + __("Cancel search") + "</a>)" +
						"</span>";
				} */

				Headlines.updateCurrentUnread();

			} else if (headlines_count > 0 && feed_id === Feeds.getActive() && is_cat === Feeds.activeIsCat()) {
				const c = dijit.byId("headlines-frame");

				let hsp = document.getElementById("headlines-spacer");

				if (hsp)
					c.domNode.removeChild(hsp);

				let headlines_appended = 0;

				if (typeof reply['headlines']['content'] === 'string') {
					document.getElementById("headlines-frame").innerHTML = reply['headlines']['content'];
				} else {
					for (let i = 0; i < reply['headlines']['content'].length; i++) {
						const hl = reply['headlines']['content'][i];

						if (!this.headlines[parseInt(hl.id)]) {
							document.getElementById("headlines-frame").appendChild(this.render(reply['headlines'], hl));

							this.headlines[parseInt(hl.id)] = hl;
							++headlines_appended;
						}
					}
				}

				Feeds.infscroll_disabled = headlines_appended === 0;

				if (!hsp) {
					hsp = document.createElement("div");
					hsp.id = "headlines-spacer";
				}

				// clear out hsp contents in case there's a power-hungry svg icon rotating there
				hsp.innerHTML = "";

				c.domNode.appendChild(hsp);

				this.initHeadlinesMenu();

				if (Feeds.infscroll_disabled) {
					hsp.innerHTML = "<a href='#' onclick='Feeds.openNextUnread()'>" +
						__("Click to open next unread feed.") + "</a>";
				}

			} else {
				Feeds.infscroll_disabled = true;
				const first_id_changed = reply['headlines']['first_id_changed'];

				const hsp = document.getElementById("headlines-spacer");

				if (hsp) {
					if (first_id_changed) {
						hsp.innerHTML = "<a href='#' onclick='Feeds.reloadCurrent()'>" +
							__("New articles found, reload feed to continue.") + "</a>";
					} else {
						hsp.innerHTML = "<a href='#' onclick='Feeds.openNextUnread()'>" +
							__("Click to open next unread feed.") + "</a>";
					}
				}
			}

		} else {
			dijit.byId("headlines-frame").attr('content', "<div class='whiteBox'>" +
				__('Could not update headlines (invalid object received - see error console for details)') +
				"</div>");
		}

		Feeds.infscroll_in_progress = 0;

		// this is used to auto-catchup articles if needed after infscroll request has finished,
		// unpack visible articles, fill buffer more, etc
		this.scrollHandler();

		dijit.byId('main').resize();

		PluginHost.run(PluginHost.HOOK_HEADLINES_RENDERED);

		Notify.close();
	},
	toggleMark: function (id) {
		document.getElementById(`RROW-${id}`)?.classList.toggle('marked');

	},
	togglePub: function (id) {
		document.getElementById(`RROW-${id}`)?.classList.toggle('published');
	},
	move: function (mode, params = {}) {
		const no_expand = params.no_expand || false;
		let prev_id = false;
		let next_id = false;
		let current_id = Article.getActive();

		if (!Headlines.isChildVisible(document.getElementById(`RROW-${current_id}`))) {
			current_id = Headlines.firstVisible();
			prev_id = current_id;
			next_id = current_id;
		} else {
			const rows = Headlines.getLoaded();

			for (let i = 0; i < rows.length; i++) {
				if (rows[i] === current_id) {

					// Account for adjacent identical article ids.
					if (i > 0) prev_id = rows[i - 1];

					for (let j = i + 1; j < rows.length; j++) {
						if (rows[j] !== current_id) {
							next_id = rows[j];
							break;
						}
					}
					break;
				}
			}
		}

		if (mode === "next") {
			if (next_id) {
				Article.view(next_id, no_expand);
			}
		} else if (mode === "prev") {
			if (prev_id || current_id) {
				if (prev_id) {
					Article.view(prev_id, no_expand);
				}
			}
		}
	},
	toggleUnread: function (id, cmode) {
		const row = document.getElementById(`RROW-${id}`);

		if (row) {
			if (typeof cmode === "undefined") cmode = 2;

			switch (cmode) {
				case 0:
					row.classList.remove('Unread');
					break;
				case 1:
					row.classList.add('Unread');
					break;
				case 2:
					row.classList.toggle('Unread');
					break;
			}
		}
	},
	removeLabel: function (label_id, id) {
		const query = {
			op: "Article", method: "removeFromLabel",
			ids: id.toString(), lid: label_id
		};

		xhr.json("backend.php", query, (reply) => {
			this.onLabelsUpdated(reply);
		});
	},
	assignLabel: function (label_id, id) {
		const query = {
			op: "Article", method: "assignToLabel",
			ids: id.toString(), lid: label_id
		};

		xhr.json("backend.php", query, (reply) => {
			this.onLabelsUpdated(reply);
		});
	},
	getLoaded: function () {
		const rv = [];

		const children = document.querySelectorAll('#headlines-frame > div[id*=RROW-]');

		children.forEach(function (child) {
			if (Element.visible(child)) {
				rv.push(parseInt(child.getAttribute('data-article-id')));
			}
		});

		return rv;
	},
	catchupRelativeTo: function (below, id) {

		if (!id) id = Article.getActive();

		if (!id) {
			alert(__("No article is selected."));
			return;
		}

		const visible_ids = this.getLoaded();

		const ids_to_mark = [];

		if (!below) {
			for (let i = 0; i < visible_ids.length; i++) {
				if (visible_ids[i] !== id) {
					const e = document.getElementById(`RROW-${visible_ids[i]}`);

					if (e && e.classList.contains('Unread')) {
						ids_to_mark.push(visible_ids[i]);
					}
				} else {
					break;
				}
			}
		} else {
			for (let i = visible_ids.length - 1; i >= 0; i--) {
				if (visible_ids[i] !== id) {
					const e = document.getElementById(`RROW-${visible_ids[i]}`);

					if (e && e.classList.contains('Unread')) {
						ids_to_mark.push(visible_ids[i]);
					}
				} else {
					break;
				}
			}
		}

		if (ids_to_mark.length === 0) {
			alert(__("No articles found to mark"));
		} else {
			const msg = ngettext("Mark %d article as read?", "Mark %d articles as read?", ids_to_mark.length).replace("%d", ids_to_mark.length);

			if (App.getInitParam("confirm_feed_catchup") !== 1 || confirm(msg)) {

				for (let i = 0; i < ids_to_mark.length; i++) {
					const e = document.getElementById(`RROW-${ids_to_mark[i]}`);
					e.classList.remove('Unread');
				}
			}
		}
	},
	onTagsUpdated: function (data) {
		if (data) {
			if (this.headlines[data.id]) {
				this.headlines[data.id].tags = data.tags;
			}

			document.querySelectorAll(`span[data-tags-for="${data.id}"`).forEach((ctr) => {
				ctr.innerHTML = Article.renderTags(data.id, data.tags);
			});
		}
	},
	// TODO: maybe this should cause article to be rendered again, although it might cause flicker etc
	onLabelsUpdated: function (data) {
		if (data) {
			data["labels-for"].forEach((row) => {
				if (this.headlines[row.id]) {
					this.headlines[row.id].labels = row.labels;
				}

				document.querySelectorAll(`span[data-labels-for="${row.id}"]`).forEach((ctr) => {
					ctr.innerHTML = Article.renderLabels(row.id, row.labels);
				});
			});
		}
	},
	scrollToArticleId: function (id) {
		const container = document.getElementById("headlines-frame");
		const row = document.getElementById(`RROW-${id}`);

		if (!container || !row) return;

		const viewport = container.offsetHeight;

		const rel_offset_top = row.offsetTop - container.scrollTop;
		const rel_offset_bottom = row.offsetTop + row.offsetHeight - container.scrollTop;

		//console.log("Rtop: " + rel_offset_top + " Rbtm: " + rel_offset_bottom);
		//console.log("Vport: " + viewport);

		if (rel_offset_top <= 0 || rel_offset_top > viewport) {
			container.scrollTop = row.offsetTop;
		} else if (rel_offset_bottom > viewport) {
			container.scrollTop = row.offsetTop + row.offsetHeight - viewport;
		}
	},
	headlinesMenuCommon: function (menu) {

		menu.addChild(new dijit.MenuItem({
			label: __("Open original article"),
			onClick: function (/* event */) {
				const id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));
				Article.openInNewWindow(id);
			}
		}));

		menu.addChild(new dijit.MenuItem({
			label: __('Copy article URL'),
			onClick: function (/* event */) {
				const id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));
				Article.copyUrl(id);
			}
		}));

		menu.addChild(new dijit.MenuSeparator());

		menu.addChild(new dijit.MenuItem({
			label: __("Toggle unread"),
			onClick: function () {
				const id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));

				Headlines.toggleUnread(id);
			}
		}));

		menu.addChild(new dijit.MenuItem({
			label: __("Toggle starred"),
			onClick: function () {
				const id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));

				Headlines.toggleMark(id);
			}
		}));

		menu.addChild(new dijit.MenuItem({
			label: __("Toggle published"),
			onClick: function () {
				const id = parseInt(this.getParent().currentTarget.getAttribute('data-article-id'));

				Headlines.togglePub(id);
			}
		}));

		menu.addChild(new dijit.MenuSeparator());

		menu.addChild(new dijit.MenuItem({
			label: __("Mark above as read"),
			onClick: function () {
				Headlines.catchupRelativeTo(0, parseInt(this.getParent().currentTarget.getAttribute('data-article-id')));
			}
		}));

		menu.addChild(new dijit.MenuItem({
			label: __("Mark below as read"),
			onClick: function () {
				Headlines.catchupRelativeTo(1, parseInt(this.getParent().currentTarget.getAttribute('data-article-id')));
			}
		}));


		const labels = App.getInitParam("labels");

		if (labels && labels.length) {

			menu.addChild(new dijit.MenuSeparator());

			const labelAddMenu = new dijit.Menu({ownerMenu: menu});
			const labelDelMenu = new dijit.Menu({ownerMenu: menu});

			labels.forEach(function (label) {
				const bare_id = label.id;
				const name = label.caption;

				labelAddMenu.addChild(new dijit.MenuItem({
					label: name,
					labelId: bare_id,
					onClick: function () {
						const id = parseInt(this.getParent().ownerMenu.currentTarget.getAttribute('data-article-id'));

						Headlines.assignLabel(this.labelId, id);
					}
				}));

				labelDelMenu.addChild(new dijit.MenuItem({
					label: name,
					labelId: bare_id,
					onClick: function () {
						const id = parseInt(this.getParent().ownerMenu.currentTarget.getAttribute('data-article-id'));

						Headlines.removeLabel(this.labelId, id);
					}
				}));

			});

			menu.addChild(new dijit.PopupMenuItem({
				label: __("Assign label"),
				popup: labelAddMenu
			}));

			menu.addChild(new dijit.PopupMenuItem({
				label: __("Remove label"),
				popup: labelDelMenu
			}));

		}
	},
	scrollByPages: function (page_offset) {
		App.Scrollable.scrollByPages(document.getElementById("headlines-frame"), page_offset);
	},
	scroll: function (offset) {
		App.Scrollable.scroll(document.getElementById("headlines-frame"), offset);
	},
	initHeadlinesMenu: function () {
		if (!dijit.byId("headlinesMenu")) {

			const menu = new dijit.Menu({
				id: "headlinesMenu",
				targetNodeIds: ["headlines-frame"],
				selector: ".hlMenuAttach"
			});

			this.headlinesMenuCommon(menu);

			menu.startup();
		}

		/* vfeed menu */

		if (!dijit.byId("vfeedMenu")) {

			const menu = new dijit.Menu({
				id: "vfeedMenu",
				targetNodeIds: ["headlines-frame"],
				selector: ".vfeedMenuAttach"
			});

			menu.addChild(new dijit.MenuItem({
				label: __("Mark as read"),
				onClick: function() {
					Feeds.catchupFeed(this.getParent().currentTarget.getAttribute("data-feed-id"));
				}}));

			menu.addChild(new dijit.MenuItem({
				label: __("Edit feed"),
				onClick: function() {
					CommonDialogs.editFeed(this.getParent().currentTarget.getAttribute("data-feed-id"), false);
				}}));

			menu.addChild(new dijit.MenuItem({
				label: __("Open site"),
				onClick: function() {
					App.postOpenWindow("backend.php", {op: "Feeds", method: "opensite",
						feed_id: this.getParent().currentTarget.getAttribute("data-feed-id"), csrf_token: __csrf_token});
				}}));

			menu.addChild(new dijit.MenuSeparator());

			menu.addChild(new dijit.MenuItem({
				label: __("Debug feed"),
				onClick: function() {
					/* global __csrf_token */
					App.postOpenWindow("backend.php", {op: "Feeds", method: "updatedebugger",
						feed_id: this.getParent().currentTarget.getAttribute("data-feed-id"), csrf_token: __csrf_token});
				}}));

			menu.startup();
		}

	}
}
